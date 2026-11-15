<?php

namespace App\Services;

use App\Enums\PlanSource;
use App\Models\Asset;
use App\Models\Floor;
use App\Models\LayoutItem;
use App\Models\Plan;
use App\Models\Room;
use App\Models\Zone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

/**
 * Подложка этажа: загрузка (PDF – страница в PNG через pdftoppm, скан или фото – как есть)
 * и калибровка масштаба. Файлы – на диске local в plans/{floor_id}/, старые версии не удаляются.
 */
class FloorPlans
{
    /** Длинная сторона картинки из PDF, px: A0 при 150 dpi и при этом без гигантских файлов. */
    public const PDF_MAX_PX = 6000;

    public function import(Floor $floor, UploadedFile $file, int $page = 1): Plan
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $source = match ($ext) {
            'pdf' => PlanSource::Pdf,
            'png', 'jpg', 'jpeg', 'webp' => PlanSource::Scan,
            'dxf', 'dwg' => throw ValidationException::withMessages(['file' => 'DXF и DWG будут на этапе 6. Пока – PDF или картинка (сохраните чертеж в PDF).']),
            default => throw ValidationException::withMessages(['file' => 'Нужен PDF, PNG, JPG или WebP.']),
        };

        $dir = "plans/{$floor->id}";
        $base = now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
        $sourcePath = $file->storeAs($dir, "{$base}.{$ext}", 'local');
        $imagePath = $source === PlanSource::Pdf ? $this->renderPdf($sourcePath, "{$dir}/{$base}.png", $page) : $sourcePath;

        $size = @getimagesize(Storage::disk('local')->path($imagePath));
        if (! $size) {
            throw ValidationException::withMessages(['file' => 'Не удалось прочитать картинку.']);
        }

        return DB::transaction(function () use ($floor, $file, $source, $sourcePath, $imagePath, $size, $page) {
            $previous = $floor->plan;
            $floor->plans()->update(['is_current' => false]);

            $plan = new Plan([
                'floor_id' => $floor->id,
                'source' => $source,
                'source_path' => $sourcePath,
                'source_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'image_path' => $imagePath,
                'width_px' => $size[0],
                'height_px' => $size[1],
                'page' => $page,
                'user_id' => Auth::id(),
            ]);
            // Замена подложки: расставленное остается в тех же метрах, а новая картинка
            // для начала растягивается на ту же ширину. Точно – калибровкой.
            if ($previous?->isCalibrated()) {
                $plan->meters_per_px = $previous->width_px * $previous->meters_per_px / $size[0];
                $plan->offset_x = $previous->offset_x;
                $plan->offset_y = $previous->offset_y;
            }
            $plan->save();

            return $plan;
        });
    }

    /**
     * Масштаб по точкам в координатах этажа, как их показывает план: две точки и длина в метрах
     * или контур помещения (3+ точки) и его площадь в м².
     *
     * Первая калибровка этажа: до нее координаты были в пикселях картинки, поэтому все
     * расставленное пересчитывается и остается на своем месте картинки. У замененной
     * подложки (раньше уже был масштаб) меняется только картинка – метры главнее.
     */
    public function calibrate(Plan $plan, array $points, float $value): void
    {
        $old = clone $plan;
        $px = array_map(fn ($p) => $old->toPixels($p[0], $p[1]), $points);
        if (count($px) === 2 && hypot($px[1][0] - $px[0][0], $px[1][1] - $px[0][1]) < 5) {
            throw ValidationException::withMessages(['value' => 'Точки слишком близко: отметьте концы известного отрезка подальше друг от друга.']);
        }
        if (count($px) > 2 && Geometry::area($px) < 100) {
            throw ValidationException::withMessages(['value' => 'Контур слишком маленький: обведите помещение целиком.']);
        }

        $first = ! $plan->floor->plans()->whereKeyNot($plan->id)->whereNotNull('meters_per_px')->exists();

        DB::transaction(function () use ($plan, $old, $px, $value, $first) {
            $plan->calibrate($px, $value);
            $plan->save();

            if ($first) {
                $this->remapFloor($plan->floor_id, fn (float $x, float $y) => $plan->toMeters(...$old->toPixels($x, $y)));
            }
        });
    }

    /**
     * Обрезать подложку по рамке (две точки в координатах этажа): новая версия подложки, только
     * кусок. PDF рендерится заново – кусок в полном разрешении; скан режется как есть.
     * С масштабом расставленное не трогается (метры те же, меняются масштаб и сдвиг картинки);
     * без масштаба координаты – пиксели картинки, поэтому пересчитываются на новую.
     */
    public function crop(Plan $plan, array $p1, array $p2): Plan
    {
        $a = $plan->toPixels(...$p1);
        $b = $plan->toPixels(...$p2);
        $W = $plan->width_px;
        $H = $plan->height_px;
        [$x0, $x1] = [max(0, min($a[0], $b[0])), min($W, max($a[0], $b[0]))];
        [$y0, $y1] = [max(0, min($a[1], $b[1])), min($H, max($a[1], $b[1]))];
        if ($x1 - $x0 < 50 || $y1 - $y0 < 50) {
            throw ValidationException::withMessages(['p2' => 'Рамка слишком маленькая или вне чертежа.']);
        }

        // Рамка – в долях всего листа (у уже обрезанной подложки – внутри прошлой рамки).
        $c = $plan->crop ?? ['x0' => 0, 'y0' => 0, 'x1' => 1, 'y1' => 1, 'aspect' => $W / $H];
        $crop = [
            'x0' => $c['x0'] + $x0 / $W * ($c['x1'] - $c['x0']), 'x1' => $c['x0'] + $x1 / $W * ($c['x1'] - $c['x0']),
            'y0' => $c['y0'] + $y0 / $H * ($c['y1'] - $c['y0']), 'y1' => $c['y0'] + $y1 / $H * ($c['y1'] - $c['y0']),
            'aspect' => $c['aspect'],
        ];

        $out = "plans/{$plan->floor_id}/".now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'-crop.png';
        if ($plan->source === PlanSource::Pdf) {
            // Очищенная страница – отдельный PDF из одной страницы.
            $plan->clean['pdf'] ?? null
                ? $this->renderPdf($plan->clean['pdf'], $out, 1, $crop)
                : $this->renderPdf($plan->source_path, $out, $plan->page, $crop);
        } else {
            $this->cropImage($plan->source_path, $out, $crop);
        }
        $size = @getimagesize(Storage::disk('local')->path($out));
        if (! $size) {
            throw ValidationException::withMessages(['p2' => 'Не удалось обрезать чертеж.']);
        }
        $f = $size[0] / ($x1 - $x0);

        return DB::transaction(function () use ($plan, $out, $size, $crop, $f, $x0, $y0) {
            $new = $plan->replicate(['is_current', 'calibration']);
            $new->fill(['image_path' => $out, 'width_px' => $size[0], 'height_px' => $size[1], 'crop' => $crop,
                'is_current' => true, 'user_id' => Auth::id(), 'calibration' => ['cropped_from' => $plan->id]]);
            if ($plan->isCalibrated()) {
                $new->meters_per_px = $plan->meters_per_px / $f;
                [$new->offset_x, $new->offset_y] = $plan->toMeters($x0, $y0);
            }
            $plan->floor->plans()->update(['is_current' => false]);
            $new->save();

            if (! $plan->isCalibrated()) {
                $this->remapFloor($plan->floor_id, fn (float $x, float $y) => $new->toMeters(
                    ...array_map(fn ($v, $o) => ($v - $o) * $f, $plan->toPixels($x, $y), [$x0, $y0])));
            }

            return $new;
        });
    }

    /**
     * Типы линий, заливок и текста страницы PDF (scripts/pdf_clean.py analyze), с отметкой скрытых.
     * Разбор – один раз на файл и страницу, дальше из кэша рядом с подложкой.
     */
    public function cleanTypes(Plan $plan): array
    {
        $dir = $this->cleanDir($plan);
        $disk = Storage::disk('local');
        if (! $disk->exists("{$dir}/types.json")) {
            $disk->makeDirectory($dir);
            $json = $this->python(['analyze', $disk->path($plan->source_path), (string) $plan->page, $disk->path("{$dir}/page.pdf")]);
            $disk->put("{$dir}/types.json", $json);
        }
        $hidden = array_flip($plan->clean['hide'] ?? []);

        return array_map(fn ($t) => $t + ['hidden' => isset($hidden[$t['key']])],
            json_decode($disk->get("{$dir}/types.json"), true)['types'] ?? []);
    }

    /** Картинка одного типа в рамке подложки (прозрачный фон): подсветить на плане, что именно скрываем. */
    public function cleanPreview(Plan $plan, int $index): string
    {
        $types = $this->cleanTypes($plan);
        abort_unless(isset($types[$index]), 404);
        $c = $plan->crop ?? ['x0' => 0, 'y0' => 0, 'x1' => 1, 'y1' => 1];
        $dir = $this->cleanDir($plan);
        $png = "{$dir}/prev-{$index}-".substr(md5(json_encode([$c['x0'], $c['y0'], $c['x1'], $c['y1']])), 0, 8).'.png';
        $disk = Storage::disk('local');
        if (! $disk->exists($png)) {
            $this->python(['preview', $disk->path("{$dir}/page.pdf"), $types[$index]['key'], $disk->path($png),
                ...array_map(fn ($v) => (string) $v, [$c['x0'], $c['y0'], $c['x1'], $c['y1'], 2400])]);
        }

        return $disk->path($png);
    }

    /**
     * Скрыть типы на подложке: страница PDF без них, новая версия подложки с той же рамкой.
     * Геометрия листа не меняется, поэтому масштаб и расставленное остаются как есть.
     */
    public function clean(Plan $plan, array $hide): Plan
    {
        $known = array_column($this->cleanTypes($plan), 'key');
        $hide = array_values(array_intersect($known, $hide));
        $disk = Storage::disk('local');
        $base = "plans/{$plan->floor_id}/".now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'-clean';
        $pdf = null;
        if ($hide) {
            $pdf = "{$base}.pdf";
            $this->python(['apply', $disk->path($plan->source_path), (string) $plan->page, $disk->path($pdf), implode(';', $hide)]);
            $this->renderPdf($pdf, "{$base}.png", 1, $plan->crop);
        } else {
            $this->renderPdf($plan->source_path, "{$base}.png", $plan->page, $plan->crop);
        }
        $size = @getimagesize($disk->path("{$base}.png"));
        if (! $size) {
            throw ValidationException::withMessages(['hide' => 'Не удалось перерисовать чертеж.']);
        }
        $f = $size[0] / $plan->width_px;

        return DB::transaction(function () use ($plan, $base, $size, $hide, $pdf, $f) {
            $new = $plan->replicate(['is_current', 'calibration']);
            $new->fill(['image_path' => "{$base}.png", 'width_px' => $size[0], 'height_px' => $size[1],
                'clean' => $hide ? ['hide' => $hide, 'pdf' => $pdf] : null,
                'is_current' => true, 'user_id' => Auth::id(), 'calibration' => ['cleaned_from' => $plan->id]]);
            if ($plan->isCalibrated()) {
                $new->meters_per_px = $plan->meters_per_px / $f;
            }
            $plan->floor->plans()->update(['is_current' => false]);
            $new->save();

            // Без масштаба координаты – пиксели; картинка того же размера, пересчет – на случай округления.
            if (! $plan->isCalibrated() && abs($f - 1) > 1e-6) {
                $this->remapFloor($plan->floor_id, fn (float $x, float $y) => [$x * $f, $y * $f]);
            }

            return $new;
        });
    }

    private function cleanDir(Plan $plan): string
    {
        abort_unless($plan->source === PlanSource::Pdf, 422, 'Чистка – только для подложки из PDF.');

        return "plans/{$plan->floor_id}/clean/".substr(md5($plan->source_path), 0, 12)."-{$plan->page}";
    }

    /** scripts/pdf_clean.py с аргументами; stdout или ошибка для пользователя. */
    private function python(array $args): string
    {
        $process = new Process([config('services.python.bin'), base_path('scripts/pdf_clean.py'), ...$args]);
        $process->setTimeout(120);
        try {
            $process->run();
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['hide' => 'Чистка недоступна: '.$e->getMessage()]);
        }
        if (! $process->isSuccessful()) {
            $err = trim($process->getErrorOutput());
            $why = str_contains($err, 'fitz') ? 'на сервере нет PyMuPDF (python3-fitz)' : (Str::afterLast($err, "\n") ?: 'python3 не найден');
            throw ValidationException::withMessages(['hide' => "Чистка не удалась: {$why}"]);
        }

        return $process->getOutput();
    }

    /** Все координаты этажа – через $mapper (метры или пиксели старой подложки -> новые). */
    private function remapFloor(int $floorId, callable $mapper): void
    {
        $map = fn (float $x, float $y) => array_map(fn ($v) => round($v, 3), $mapper($x, $y));

        Room::where('floor_id', $floorId)->whereNotNull('polygon')->each(function (Room $room) use ($map) {
            $room->update(['polygon' => array_map(fn ($p) => $map($p[0], $p[1]), $room->polygon), 'area_m2' => null]);
        });
        Room::where('floor_id', $floorId)->whereNotNull('label_x')->each(function (Room $room) use ($map) {
            [$x, $y] = $map((float) $room->label_x, (float) $room->label_y);
            $room->update(['label_x' => $x, 'label_y' => $y]);
        });
        Zone::where('floor_id', $floorId)->each(function (Zone $zone) use ($map) {
            $zone->update(['polygon' => array_map(fn ($p) => $map($p[0], $p[1]), $zone->polygon)]);
        });
        // Точка внутри того же помещения – это не перемещение, AssetMover не нужен.
        Asset::where('floor_id', $floorId)->whereNotNull('x')->each(function (Asset $asset) use ($map) {
            [$x, $y] = $map($asset->x, $asset->y);
            $asset->forceFill(['x' => $x, 'y' => $y])->save();
        });
        LayoutItem::whereHas('layout', fn ($q) => $q->where('floor_id', $floorId))->each(function (LayoutItem $item) use ($map) {
            [$x, $y] = $map($item->x, $item->y);
            $item->update(['x' => $x, 'y' => $y]);
        });
    }

    /** Программа poppler-utils: из services.poppler.bin, иначе по имени из PATH. */
    public static function poppler(string $tool): string
    {
        $dir = rtrim((string) config('services.poppler.bin'), '/\\');

        return $dir !== '' ? $dir.DIRECTORY_SEPARATOR.$tool : $tool;
    }

    /** Помещение этажа, в котором точка (первое по номеру), или null – коридор, вне контуров. */
    public function roomAt(Floor $floor, float $x, float $y): ?Room
    {
        return $floor->rooms()->whereNotNull('polygon')->get()
            ->first(fn (Room $room) => Geometry::contains($room->polygon, $x, $y));
    }

    /** Скан: вырезать рамку (доли картинки) из исходника. */
    private function cropImage(string $srcPath, string $pngPath, array $crop): void
    {
        $disk = Storage::disk('local');
        $img = @imagecreatefromstring($disk->get($srcPath));
        if (! $img) {
            throw ValidationException::withMessages(['p2' => 'Не удалось прочитать картинку.']);
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $part = imagecrop($img, ['x' => (int) round($crop['x0'] * $w), 'y' => (int) round($crop['y0'] * $h),
            'width' => (int) round(($crop['x1'] - $crop['x0']) * $w), 'height' => (int) round(($crop['y1'] - $crop['y0']) * $h)]);
        imagedestroy($img);
        imagepng($part, $disk->path($pngPath));
        imagedestroy($part);
    }

    /** @param  ?array  $crop  рамка в долях листа: кусок рендерится так, чтобы его длинная сторона была PDF_MAX_PX */
    private function renderPdf(string $pdfPath, string $pngPath, int $page, ?array $crop = null): string
    {
        $disk = Storage::disk('local');
        $out = substr($disk->path($pngPath), 0, -4);

        // Большой чертеж на время занимает сотни мегабайт: по одному за раз на весь сервер.
        $lock = Cache::lock('plan-pdf-render', 180);
        if (! $lock->block(60)) {
            throw ValidationException::withMessages(['file' => 'Сервер занят другим чертежом, повторите через минуту.']);
        }
        try {
            $args = ['-r', '150', '-scale-to', (string) self::PDF_MAX_PX];
            if ($crop) {
                // При -scale-to S длинная сторона листа – S пикселей; доли рамки – в пиксели такого листа.
                [$wu, $hu] = $crop['aspect'] >= 1 ? [1, 1 / $crop['aspect']] : [$crop['aspect'], 1];
                $scale = min(30000, self::PDF_MAX_PX / max(($crop['x1'] - $crop['x0']) * $wu, ($crop['y1'] - $crop['y0']) * $hu));
                $args = ['-scale-to', (string) round($scale),
                    '-x', (string) round($crop['x0'] * $wu * $scale), '-y', (string) round($crop['y0'] * $hu * $scale),
                    '-W', (string) round(($crop['x1'] - $crop['x0']) * $wu * $scale), '-H', (string) round(($crop['y1'] - $crop['y0']) * $hu * $scale)];
            }
            $process = new Process([self::poppler('pdftoppm'), '-png', '-singlefile', '-f', (string) $page, '-l', (string) $page,
                ...$args, $disk->path($pdfPath), $out]);
            $process->setTimeout(150);
            $process->run();
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['file' => 'Не удалось разобрать PDF: '.$e->getMessage()]);
        } finally {
            $lock->release();
        }

        if (! $process->isSuccessful() || ! $disk->exists($pngPath)) {
            $why = trim($process->getErrorOutput()) ?: 'нет pdftoppm (poppler-utils) на сервере';
            throw ValidationException::withMessages(['file' => "Не удалось разобрать PDF (страница {$page}): {$why}"]);
        }

        return $pngPath;
    }
}
