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
     * Масштаб по двум точкам (в координатах этажа, как их показывает план) и расстоянию в метрах.
     *
     * Первая калибровка этажа: до нее координаты были в пикселях картинки, поэтому все
     * расставленное пересчитывается и остается на своем месте картинки. У замененной
     * подложки (раньше уже был масштаб) меняется только картинка – метры главнее.
     */
    public function calibrate(Plan $plan, array $p1, array $p2, float $meters): void
    {
        $old = clone $plan;
        $px1 = $old->toPixels($p1[0], $p1[1]);
        $px2 = $old->toPixels($p2[0], $p2[1]);
        if (hypot($px2[0] - $px1[0], $px2[1] - $px1[1]) < 5) {
            throw ValidationException::withMessages(['meters' => 'Точки слишком близко: отметьте концы известного отрезка подальше друг от друга.']);
        }

        $first = ! $plan->floor->plans()->whereKeyNot($plan->id)->whereNotNull('meters_per_px')->exists();

        DB::transaction(function () use ($plan, $old, $px1, $px2, $meters, $first) {
            $plan->calibrate($px1, $px2, $meters);
            $plan->save();

            if (! $first) {
                return;
            }
            $map = fn (float $x, float $y) => array_map(fn ($v) => round($v, 3), $plan->toMeters(...$old->toPixels($x, $y)));
            $floorId = $plan->floor_id;

            Room::where('floor_id', $floorId)->whereNotNull('polygon')->each(function (Room $room) use ($map) {
                $room->update(['polygon' => array_map(fn ($p) => $map($p[0], $p[1]), $room->polygon), 'area_m2' => null]);
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
        });
    }

    /** Помещение этажа, в котором точка (первое по номеру), или null – коридор, вне контуров. */
    public function roomAt(Floor $floor, float $x, float $y): ?Room
    {
        return $floor->rooms()->whereNotNull('polygon')->get()
            ->first(fn (Room $room) => Geometry::contains($room->polygon, $x, $y));
    }

    private function renderPdf(string $pdfPath, string $pngPath, int $page): string
    {
        $disk = Storage::disk('local');
        $out = substr($disk->path($pngPath), 0, -4);

        // Большой чертеж на время занимает сотни мегабайт: по одному за раз на весь сервер.
        $lock = Cache::lock('plan-pdf-render', 180);
        if (! $lock->block(60)) {
            throw ValidationException::withMessages(['file' => 'Сервер занят другим чертежом, повторите через минуту.']);
        }
        try {
            $process = new Process(['pdftoppm', '-png', '-singlefile', '-f', (string) $page, '-l', (string) $page,
                '-r', '150', '-scale-to', (string) self::PDF_MAX_PX, $disk->path($pdfPath), $out]);
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
