<?php

namespace App\Services;

use App\Enums\PlanSource;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Room;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

/**
 * Импорт из рабочего проекта (PDF из Revit / AutoCAD): помещения из экспликации и
 * мебель и оборудование по меткам плана расстановки со спецификацией.
 *
 * Текст листов – pdftotext -bbox (poppler-utils): слова с координатами в точках листа.
 * - Экспликация – таблица "Номер / Имя / Площадь"; метка помещения на подложке – его номер
 *   (или площадь) вне таблиц.
 * - Спецификация – таблицы "№ / Наименование (Описание) / Кол-во (Число) / ... помещение";
 *   строка – по числу в столбце количества, помещение – по номеру из экспликации.
 * - Метки (М27, м12, Э1, Z1) – на листе расстановки. Если это не лист подложки, листы
 *   совмещаются по помещениям: подбирается масштаб и сдвиг, при которых метки помещений
 *   (номера, площади) одного листа ложатся на метки другого.
 */
class ProjectImport
{
    private const AREA = '/^\d{1,4}[,.]\d{1,2}$/u';

    private const MARK = '/^\p{L}{1,2}\d{1,3}$/u';

    /** Слова листа: ['w' => ширина, 'h' => высота (без поворота), 'words' => [[текст, x0, y0, x1, y1], ...]]. */
    public function words(string $pdfPath, int $page): array
    {
        $process = new Process([FloorPlans::poppler('pdftotext'), '-f', (string) $page, '-l', (string) $page, '-bbox', $pdfPath, '-']);
        $process->setTimeout(120);
        try {
            $process->run();
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['page' => 'Не удалось прочитать текст PDF: '.$e->getMessage()]);
        }
        if (! $process->isSuccessful()) {
            $why = trim($process->getErrorOutput()) ?: 'нет pdftotext (poppler-utils) на сервере';
            throw ValidationException::withMessages(['page' => "Не удалось прочитать текст PDF (страница {$page}): {$why}"]);
        }

        return self::parseBbox($process->getOutput());
    }

    public static function parseBbox(string $html): array
    {
        preg_match('/<page width="([\d.]+)" height="([\d.]+)"/', $html, $p);
        preg_match_all('/<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">(.*?)<\/word>/su', $html, $m, PREG_SET_ORDER);

        return [
            'w' => (float) ($p[1] ?? 0),
            'h' => (float) ($p[2] ?? 0),
            'words' => array_map(fn ($w) => [html_entity_decode($w[5], ENT_QUOTES | ENT_HTML5, 'UTF-8'), (float) $w[1], (float) $w[2], (float) $w[3], (float) $w[4]], $m),
        ];
    }

    /**
     * Что будет импортировано – для просмотра и для apply().
     *
     * @return array{rooms: list<array>, items: list<array>, groups: array<string, array>, notes: list<string>}
     */
    public function analyze(Plan $plan, ?int $marksPage = null, ?int $specPage = null): array
    {
        abort_unless($plan->source === PlanSource::Pdf && $plan->source_path, 422, 'Импорт – только из PDF: подложка этажа должна быть листом проекта.');
        $pdf = Storage::disk('local')->path($plan->source_path);

        $pages = [$plan->page => $this->words($pdf, $plan->page)];
        foreach ([$marksPage, $specPage] as $n) {
            if ($n && ! isset($pages[$n])) {
                $pages[$n] = $this->words($pdf, $n);
            }
        }

        return self::build($pages, $plan->page, $marksPage, $specPage, $plan->width_px, $plan->height_px,
            fn (float $px, float $py) => $plan->toMeters($px, $py), $plan->isCalibrated(), $plan->crop);
    }

    /**
     * Разбор без файлов и БД (тесты – на этом). $toFloor – пиксели картинки подложки в координаты этажа.
     *
     * @param  array<int, array>  $pages  номер листа => words()
     */
    public static function build(array $pages, int $basePage, ?int $marksPage, ?int $specPage, int $imgW, int $imgH, callable $toFloor, bool $calibrated = true, ?array $crop = null): array
    {
        $notes = [];

        // Экспликация – на листе подложки, иначе на остальных.
        $roomTables = [];
        $rooms = [];
        foreach (array_unique(array_filter([$basePage, $marksPage, $specPage])) as $n) {
            $roomTables[$n] = self::roomTables($pages[$n]['words']);
            if (! $rooms) {
                foreach ($roomTables[$n] as $t) {
                    $rooms += $t['rows'];
                }
            }
        }
        if (! $rooms) {
            throw ValidationException::withMessages(['page' => 'На листах не нашлась экспликация (таблица "Номер / Имя / Площадь").']);
        }

        $itemTables = $specPage ? self::itemTables($pages[$specPage]['words'], array_keys($rooms)) : [];
        $tableBoxes = fn (int $n) => array_merge(array_column($roomTables[$n] ?? [], 'box'),
            $n === $specPage ? array_column($itemTables, 'box') : []);

        // Метки помещений на подложке: в пиксели картинки. Картинка – весь лист или рамка (crop, доли листа).
        $base = $pages[$basePage];
        $crop ??= ['x0' => 0, 'y0' => 0, 'x1' => 1, 'y1' => 1, 'aspect' => $imgW / max($imgH, 1)];
        [$dispW, $dispH] = ($base['w'] > $base['h']) === ($crop['aspect'] > 1) ? [$base['w'], $base['h']] : [$base['h'], $base['w']];
        $scale = $dispW > 0 ? $imgW / ($dispW * ($crop['x1'] - $crop['x0'])) : 1;
        $toPx = fn (array $p) => [($p[0] - $crop['x0'] * $dispW) * $scale, ($p[1] - $crop['y0'] * $dispH) * $scale];
        $baseAnchors = self::anchors($base['words'], $rooms, $tableBoxes($basePage));
        foreach ($rooms as $num => &$room) {
            $p = self::labelPoint($baseAnchors[$num] ?? []);
            $px = $p ? $toPx($p) : null;
            // Метка вне рамки – помещение не на этом куске листа.
            $room['label'] = $px && $px[0] >= 0 && $px[1] >= 0 && $px[0] <= $imgW && $px[1] <= $imgH
                ? array_map(fn ($v) => round($v, 3), $toFloor(...$px)) : null;
        }
        unset($room);
        $missing = count(array_filter($rooms, fn ($r) => ! $r['label']));
        if ($missing) {
            $notes[] = "Не нашлись на подложке: {$missing} помещ. – их можно будет обвести, но без метки.";
        }

        // Мебель и оборудование: метки на листе расстановки, описание – из спецификации.
        $items = [];
        $groups = [];
        if ($marksPage && $specPage) {
            $rows = array_merge(...array_column($itemTables, 'rows') ?: [[]]);
            $byMark = [];
            foreach ($rows as $r) {
                if ($r['mark'] !== null) {
                    $byMark[$r['mark']][] = $r;
                }
            }
            $noMark = count(array_filter($rows, fn ($r) => $r['mark'] === null));
            if ($noMark) {
                $notes[] = "В спецификации без метки: {$noMark} строк – их не на что поставить, пропущены.";
            }

            $marksWords = $pages[$marksPage]['words'];
            $marksAnchors = $marksPage === $basePage ? $baseAnchors : self::anchors($marksWords, $rooms, $tableBoxes($marksPage));
            if ($marksPage === $basePage) {
                $fit = ['s' => 1.0, 'tx' => 0.0, 'ty' => 0.0, 'rooms' => count($baseAnchors), 'error' => 0.0];
            } else {
                $fit = self::register($marksAnchors, $baseAnchors);
                if (! $fit) {
                    throw ValidationException::withMessages(['marks_page' => 'Не удалось совместить лист расстановки с подложкой: мало общих помещений (номеров или площадей).']);
                }
                $notes[] = $calibrated
                    ? sprintf('Листы совмещены по %d помещениям, расхождение около %.1f м.', $fit['rooms'], self::pointsToMeters($fit['error'] * $scale, $toFloor))
                    : sprintf('Листы совмещены по %d помещениям, расхождение около %.1f%% ширины листа.', $fit['rooms'], 100 * $fit['error'] / max($dispW, 1));
            }

            // Метки – только в пределах плана (не легенда и не штамп).
            $all = array_merge(...array_values(array_map(fn ($a) => array_column($a, 'p'), $marksAnchors)) ?: [[]]);
            $box = self::bbox($all, 0.15);
            $boxes = $tableBoxes($marksPage);
            $unknown = [];
            foreach ($marksWords as $w) {
                [$t, $x0, $y0, $x1, $y1] = $w;
                if (! preg_match(self::MARK, $t) || isset($rooms[$t])) {
                    continue;
                }
                $c = [($x0 + $x1) / 2, ($y0 + $y1) / 2];
                if (! $box || ! self::inBox($c, $box) || self::inAny($c, $boxes)) {
                    continue;
                }
                if (! isset($byMark[$t])) {
                    $unknown[$t] = true;

                    continue;
                }
                // Одна метка в разных помещениях (стул в кабинете и в ординаторской) – строка ближайшего помещения.
                $row = collect($byMark[$t])->sortBy(fn ($r) => self::nearest($c, array_column($marksAnchors[$r['room']] ?? [], 'p')))->first();
                $q = [$fit['s'] * $c[0] + $fit['tx'], $fit['s'] * $c[1] + $fit['ty']];
                $px = $toPx($q);
                if ($px[0] < 0 || $px[1] < 0 || $px[0] > $imgW || $px[1] > $imgH) {
                    continue;
                }
                $xy = array_map(fn ($v) => round($v, 3), $toFloor(...$px));
                $items[] = ['mark' => $t, 'name' => $row['name'], 'group' => $row['group'], 'room' => $row['room'],
                    'catalog' => $row['catalog'], 'x' => $xy[0], 'y' => $xy[1],
                    'ref' => sha1($marksPage.'|'.$t.'|'.round($c[0], 1).'|'.round($c[1], 1))];
                $groups[$row['group']] ??= ['title' => $row['groupTitle'], 'count' => 0];
                $groups[$row['group']]['count']++;
            }
            if ($unknown) {
                $notes[] = 'Метки без строки в спецификации: '.implode(', ', array_slice(array_keys($unknown), 0, 15)).(count($unknown) > 15 ? '…' : '').'.';
            }
        }

        return ['rooms' => array_values(array_map(fn ($n, $r) => ['number' => (string) $n] + $r, array_keys($rooms), $rooms)), 'items' => $items, 'groups' => $groups, 'notes' => $notes];
    }

    /**
     * Создать помещения (по номеру: есть – дописать) и единицы (повторный импорт их не дублирует).
     *
     * @param  array<string, int>  $categories  группа спецификации => категория; нет группы – ее не импортировать
     * @return array{rooms: int, assets: int}
     */
    public function apply(Plan $plan, array $result, array $categories, AssetMover $mover): array
    {
        $floor = $plan->floor()->with('building')->first();

        return DB::transaction(function () use ($floor, $result, $categories, $mover) {
            $roomIds = [];
            $newRooms = 0;
            foreach ($result['rooms'] as $r) {
                $room = Room::firstOrNew(['floor_id' => $floor->id, 'number' => $r['number']]);
                $newRooms += $room->exists ? 0 : 1;
                $room->name = $room->name ?: $r['name'];
                if (! $room->polygon) {
                    $room->area_m2 = $r['area'];
                }
                if ($r['label']) {
                    [$room->label_x, $room->label_y] = $r['label'];
                }
                $room->save();
                $roomIds[$r['number']] = $room->id;
            }

            $cats = Category::whereIn('id', array_filter($categories))->get()->keyBy('id');
            $everything = Category::orderBy('sort')->get();
            $known = Asset::withTrashed()->whereIn('source_ref', array_column($result['items'], 'ref'))->pluck('source_ref')->flip();
            $assets = 0;
            foreach ($result['items'] as $it) {
                $cat = $cats[$categories[$it['group']] ?? 0] ?? null;
                if (! $cat || isset($known[$it['ref']])) {
                    continue;
                }
                $cat = self::guessCategory($it['name'], $everything->where('layer_id', $cat->layer_id))
                    ?? self::guessCategory($it['name'], $everything->where('layer_id', '!=', $cat->layer_id)) ?? $cat;
                $asset = Asset::create([
                    'category_id' => $cat->id, 'name' => mb_substr($it['name'], 0, 191), 'office_id' => $floor->building->office_id,
                    'source_ref' => $it['ref'], 'notes' => $it['catalog'] ? "По проекту: метка {$it['mark']}, каталог {$it['catalog']}" : "По проекту: метка {$it['mark']}",
                ]);
                $mover->move($asset, ['floor_id' => $floor->id, 'room_id' => $roomIds[$it['room']] ?? null, 'x' => $it['x'], 'y' => $it['y']], 'Импорт из проекта');
                $assets++;
            }

            return ['rooms' => $newRooms, 'assets' => $assets];
        });
    }

    /** Общие слова – по ним категорию не угадать ("Шкаф медицинский" – не "Точки медицинских газов"). */
    private const STOP_STEMS = ['меди', 'обор', 'проч', 'инже', 'лабо', 'мебе', 'техн', 'безо', 'сист'];

    /**
     * Категория по словам названия: "Офисное кресло" – "Стулья и кресла", "Ноутбук Lenovo" –
     * "Компьютеры и ноутбуки". Слова совпадают, если различаются только окончанием: общее начало
     * не короче 5 букв и всего слова без двух последних (стол – столы, но микроволновая – не микроскопы).
     */
    public static function guessCategory(string $name, $categories): ?Category
    {
        $words = fn (string $s) => array_values(array_filter(preg_split('/[^\p{L}]+/u', mb_strtolower($s)),
            fn ($w) => mb_strlen($w) >= 4 && ! in_array(mb_substr($w, 0, 4), self::STOP_STEMS, true)));
        $same = function (string $a, string $b): bool {
            $n = 0;
            $max = min(mb_strlen($a), mb_strlen($b));
            while ($n < $max && mb_substr($a, $n, 1) === mb_substr($b, $n, 1)) {
                $n++;
            }

            return $n >= min(5, $max) && $n >= $max - 2;
        };
        $item = $words($name);

        return collect($categories)->first(function (Category $c) use ($words, $same, $item) {
            foreach ($words($c->name) as $cw) {
                foreach ($item as $iw) {
                    if ($same($iw, $cw)) {
                        return true;
                    }
                }
            }

            return false;
        });
    }

    /**
     * Таблицы экспликации: [['box' => [x0, y0, x1, y1], 'rows' => [номер => ['name', 'area']]]].
     *
     * @param  list<array>  $words
     */
    public static function roomTables(array $words): array
    {
        $tables = [];
        foreach ($words as $h) {
            if (mb_strtolower($h[0]) !== 'площадь') {
                continue;
            }
            $line = array_filter($words, fn ($w) => abs($w[2] - $h[2]) < 4 && $w[1] < $h[1] && $h[1] - $w[1] < 400);
            $num = collect($line)->filter(fn ($w) => mb_strtolower($w[0]) === 'номер')->sortByDesc(fn ($w) => $w[1])->first();
            // Таблицы стоят рядом: столбец имени – между своим номером и своей площадью.
            $nameH = $num ? collect($line)->first(fn ($w) => in_array(mb_strtolower($w[0]), ['имя', 'наименование', 'название'], true) && $w[1] > $num[1]) : null;
            if (! $nameH) {
                continue;
            }
            $areaFrom = ($nameH[3] + $h[1]) / 2;
            $right = $h[3] + 25;
            $stop = collect($words)->filter(fn ($w) => preg_match('/итог/ui', $w[0]) && $w[2] > $h[4] && $w[1] >= $num[1] - 5 && $w[1] <= $right)->min(fn ($w) => $w[2]) ?? INF;

            $anchors = collect($words)->filter(fn ($w) => preg_match(self::AREA, $w[0]) && $w[1] >= $areaFrom && $w[1] <= $right && $w[2] > $h[4] && $w[2] < $stop)
                ->sortBy(fn ($w) => $w[2])->values();
            // Конец таблицы – большой разрыв между строками.
            $kept = [];
            foreach ($anchors as $a) {
                if ($kept && $a[2] - end($kept)[2] > 40) {
                    break;
                }
                $kept[] = $a;
            }

            $rows = [];
            $split = self::splitRows($kept, array_filter($words, fn ($w) => $w[1] >= $num[1] - 5 && $w[1] < $areaFrom), $stop);
            foreach ($kept as $i => $a) {
                $cells = collect($split[$i]);
                $numWord = $cells->first(fn ($w) => $w[1] < $nameH[1] - 2 && abs($w[2] - $a[2]) < 3);
                if (! $numWord || preg_match('/итог/ui', $numWord[0])) {
                    continue;
                }
                $name = $cells->reject(fn ($w) => $w === $numWord)->pluck(0)->implode(' ');
                $rows[$numWord[0]] = ['name' => trim($name), 'area' => (float) str_replace(',', '.', $a[0])];
            }
            if ($rows) {
                $last = end($kept);
                $tables[] = ['box' => [$num[1] - 8, $h[2] - 30, $right + 5, $last[4] + 25], 'rows' => $rows];
            }
        }

        return $tables;
    }

    /**
     * Таблицы спецификации мебели и оборудования: [['box', 'rows' => [mark, name, qty, room, catalog, group, groupTitle]]].
     *
     * @param  list<string>  $roomNumbers  номера из экспликации – по ним опознается помещение строки
     */
    public static function itemTables(array $words, array $roomNumbers): array
    {
        $roomNumbers = array_flip(array_map('strval', $roomNumbers));
        $heads = collect($words)->filter(fn ($w) => $w[0] === '№')->sortBy(fn ($w) => $w[1])->values();
        $tables = [];
        foreach ($heads as $i => $h) {
            $right = collect($heads)->filter(fn ($o) => $o[1] > $h[1] + 20 && abs($o[2] - $h[2]) < 300)->min(fn ($o) => $o[1]) ?? $h[1] + 400;
            $right -= 4;
            $line = collect($words)->filter(fn ($w) => abs($w[2] - $h[2]) < 7 && $w[1] > $h[1] && $w[1] < $right);
            $nameH = $line->first(fn ($w) => preg_match('/^(наимен|описан)/ui', $w[0]));
            $qtyH = $line->first(fn ($w) => preg_match('/^(кол|числ)/ui', $w[0]));
            if (! $nameH || ! $qtyH || $qtyH[1] < $nameH[1]) {
                continue;
            }
            // Заголовок таблицы – ближайшая сверху строка со словом "Спецификация".
            $head = collect($words)->filter(fn ($w) => preg_match('/^специфика/ui', $w[0]) && $w[2] < $h[2] - 4 && $h[2] - $w[2] < 40 && $w[1] >= $h[1] - 40 && $w[1] < $right)
                ->sortByDesc(fn ($w) => $w[2])->first();
            $titleWords = $head ? collect($words)->filter(fn ($w) => abs($w[2] - $head[2]) < 2 && $w[1] >= $head[1] && $w[1] < $right)->sortBy(fn ($w) => $w[1]) : collect();
            $title = trim(preg_replace('/\s*\d+\s*-?\s*го\s+этаж\w*|\s*этаж\w*/u', '', preg_replace('/^специфика\w*\s*/ui', '', $titleWords->pluck(0)->implode(' '))));
            $group = mb_strtolower($title ?: 'спецификация');

            $stop = collect($words)->filter(fn ($w) => preg_match('/итог/ui', $w[0]) && $w[2] > $h[4] && $w[1] >= $h[1] - 5 && $w[1] < $right)->min(fn ($w) => $w[2]) ?? INF;
            $anchors = collect($words)->filter(fn ($w) => preg_match('/^\d{1,4}$/', $w[0]) && $w[1] >= $qtyH[1] - 8 && $w[3] <= $qtyH[3] + 10 && $w[2] > $h[4] + 1 && $w[2] < $stop)
                ->sortBy(fn ($w) => $w[2])->values();
            $kept = [];
            foreach ($anchors as $a) {
                if ($kept && $a[2] - end($kept)[2] > 40) {
                    break;
                }
                $kept[] = $a;
            }

            $rows = [];
            $split = self::splitRows($kept, array_filter($words, fn ($w) => $w[1] >= $h[1] - 4 && $w[1] < $right), $stop);
            foreach ($kept as $j => $a) {
                $cells = collect($split[$j])->reject(fn ($w) => $w === $a)->values();
                $mark = $cells->first(fn ($w) => $w[1] < $h[3] + 8 && preg_match(self::MARK, $w[0]));
                // Заголовок столбца бывает по центру, а текст – от левого края: имя – от столбца "№" до количества.
                $name = $cells->filter(fn ($w) => $w !== $mark && $w[1] > $h[3] + 1 && $w[1] < $qtyH[1] - 3)->pluck(0)->implode(' ');
                $after = $cells->filter(fn ($w) => $w[1] > $a[3]);
                $room = $after->first(fn ($w) => isset($roomNumbers[$w[0]]));
                $catalog = $after->first(fn ($w) => preg_match('/^\d+\p{Lu}{2,}\d*$/u', $w[0]));
                $rows[] = ['mark' => $mark[0] ?? null, 'name' => trim($name), 'qty' => (int) $a[0], 'room' => $room[0] ?? null,
                    'catalog' => $catalog[0] ?? null, 'group' => $group, 'groupTitle' => $title ? mb_strtoupper(mb_substr($title, 0, 1)).mb_substr($title, 1) : 'Спецификация'];
            }
            if ($rows) {
                $tables[] = ['box' => [$h[1] - 8, $h[2] - 30, $right, end($kept)[4] + 25], 'rows' => $rows];
            }
        }

        return $tables;
    }

    /**
     * Слова таблицы по строкам: каждое – к строке, чье опорное слово ближе по высоте.
     * Так верно и для ячеек, выровненных по верху (экспликация), и по центру (спецификация):
     * у многострочного названия соседние строки отделены еще и отступом ячейки.
     *
     * @param  list<array>  $anchors  опорные слова строк сверху вниз
     * @return list<list<array>> слова строк в порядке чтения
     */
    private static function splitRows(array $anchors, array $words, float $stop): array
    {
        $rows = array_fill(0, count($anchors), []);
        if (! $anchors) {
            return $rows;
        }
        $ys = array_column($anchors, 2);
        $gap = count($ys) > 1 ? min(array_map(fn ($a, $b) => $b - $a, array_slice($ys, 0, -1), array_slice($ys, 1))) : 10;
        foreach ($words as $w) {
            if ($w[2] < $ys[0] - 0.8 * $gap || $w[2] > end($ys) + 1.6 * $gap || $w[2] >= $stop - 1) {
                continue;
            }
            $best = 0;
            foreach ($ys as $k => $y) {
                if (abs($w[2] - $y) < abs($w[2] - $ys[$best])) {
                    $best = $k;
                }
            }
            $rows[$best][] = $w;
        }

        return array_map(fn ($r) => collect($r)->sortBy(fn ($w) => [round($w[2]), $w[1]])->values()->all(), $rows);
    }

    /**
     * Метки помещений на листе вне таблиц: номер => [['p' => [x, y], 'kind' => number|area], ...].
     *
     * @param  array<string, array>  $rooms
     */
    public static function anchors(array $words, array $rooms, array $boxes): array
    {
        $byArea = [];
        foreach ($rooms as $num => $r) {
            $byArea[number_format($r['area'], 2, ',', '')][] = (string) $num;
        }
        $out = [];
        foreach ($words as [$t, $x0, $y0, $x1, $y1]) {
            $c = [($x0 + $x1) / 2, ($y0 + $y1) / 2];
            if (self::inAny($c, $boxes)) {
                continue;
            }
            if (isset($rooms[$t])) {
                $out[$t][] = ['p' => $c, 'kind' => 'number'];
            }
            foreach ($byArea[str_replace('.', ',', $t)] ?? [] as $num) {
                $out[$num][] = ['p' => $c, 'kind' => 'area'];
            }
        }

        return $out;
    }

    /** Точка метки: номер (ближайший к своей площади, если номеров несколько), иначе площадь. */
    private static function labelPoint(array $anchors): ?array
    {
        $numbers = array_column(array_filter($anchors, fn ($a) => $a['kind'] === 'number'), 'p');
        $areas = array_column(array_filter($anchors, fn ($a) => $a['kind'] === 'area'), 'p');
        if ($numbers) {
            return $areas ? collect($numbers)->sortBy(fn ($p) => self::nearest($p, $areas))->first() : $numbers[0];
        }

        return $areas[0] ?? null;
    }

    /**
     * Масштаб и сдвиг листа $from к листу $to по общим помещениям (перебор пар, затем МНК по совпавшим).
     *
     * @return ?array{s: float, tx: float, ty: float, rooms: int, error: float}
     */
    public static function register(array $from, array $to): ?array
    {
        $pairs = [];
        foreach ($from as $num => $as) {
            foreach ($as as $a) {
                foreach ($to[$num] ?? [] as $b) {
                    $pairs[] = [$num, $a['p'], $b['p']];
                }
            }
        }
        if (count(array_unique(array_column($pairs, 0))) < 3) {
            return null;
        }
        $all = array_merge(...array_values(array_map(fn ($a) => array_column($a, 'p'), $to)));
        $box = self::bbox($all, 0);
        $tol = 0.04 * hypot($box[2] - $box[0], $box[3] - $box[1]);

        $best = null;
        $n = count($pairs);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                [$ni, $pi, $qi] = $pairs[$i];
                [$nj, $pj, $qj] = $pairs[$j];
                if ($ni === $nj) {
                    continue;
                }
                $dp = [$pj[0] - $pi[0], $pj[1] - $pi[1]];
                $dq = [$qj[0] - $qi[0], $qj[1] - $qi[1]];
                $lp = hypot(...$dp);
                $lq = hypot(...$dq);
                // Короткая база дает неточный масштаб; листы не повернуты друг относительно друга.
                if ($lp < 20 || $lq < 20 || ($dp[0] * $dq[0] + $dp[1] * $dq[1]) / ($lp * $lq) < 0.98) {
                    continue;
                }
                $s = $lq / $lp;
                $t = [$qi[0] - $s * $pi[0], $qi[1] - $s * $pi[1]];
                $inliers = self::inliers($pairs, $s, $t, $tol);
                if (! $best || count($inliers) > count($best)) {
                    $best = $inliers;
                }
            }
        }
        if (! $best || count($best) < 3) {
            return null;
        }

        // МНК: s, tx, ty по совпавшим помещениям (у каждого – лучшая пара).
        $p = array_column($best, 1);
        $q = array_column($best, 2);
        $pm = [array_sum(array_column($p, 0)) / count($p), array_sum(array_column($p, 1)) / count($p)];
        $qm = [array_sum(array_column($q, 0)) / count($q), array_sum(array_column($q, 1)) / count($q)];
        $num = $den = 0;
        foreach ($p as $k => $pk) {
            $num += ($pk[0] - $pm[0]) * ($q[$k][0] - $qm[0]) + ($pk[1] - $pm[1]) * ($q[$k][1] - $qm[1]);
            $den += ($pk[0] - $pm[0]) ** 2 + ($pk[1] - $pm[1]) ** 2;
        }
        $s = $num / $den;
        $t = [$qm[0] - $s * $pm[0], $qm[1] - $s * $pm[1]];
        $err = array_sum(array_map(fn ($pk, $qk) => hypot($s * $pk[0] + $t[0] - $qk[0], $s * $pk[1] + $t[1] - $qk[1]), $p, $q)) / count($p);

        return ['s' => $s, 'tx' => $t[0], 'ty' => $t[1], 'rooms' => count($best), 'error' => $err];
    }

    /** Совпавшие помещения: номер => [номер, p, q] с наименьшей ошибкой. */
    private static function inliers(array $pairs, float $s, array $t, float $tol): array
    {
        $out = [];
        foreach ($pairs as $pair) {
            [$num, $p, $q] = $pair;
            $d = hypot($s * $p[0] + $t[0] - $q[0], $s * $p[1] + $t[1] - $q[1]);
            if ($d < $tol && (! isset($out[$num]) || $d < $out[$num][3])) {
                $out[$num] = [...$pair, $d];
            }
        }

        return $out;
    }

    private static function nearest(array $p, array $points): float
    {
        return $points ? min(array_map(fn ($q) => hypot($p[0] - $q[0], $p[1] - $q[1]), $points)) : INF;
    }

    private static function bbox(array $points, float $pad): ?array
    {
        if (! $points) {
            return null;
        }
        $xs = array_column($points, 0);
        $ys = array_column($points, 1);
        $dx = (max($xs) - min($xs)) * $pad;
        $dy = (max($ys) - min($ys)) * $pad;

        return [min($xs) - $dx, min($ys) - $dy, max($xs) + $dx, max($ys) + $dy];
    }

    private static function inBox(array $p, array $b): bool
    {
        return $p[0] >= $b[0] && $p[0] <= $b[2] && $p[1] >= $b[1] && $p[1] <= $b[3];
    }

    private static function inAny(array $p, array $boxes): bool
    {
        foreach ($boxes as $b) {
            if (self::inBox($p, $b)) {
                return true;
            }
        }

        return false;
    }

    /** Отрезок в пикселях подложки – в метры этажа (для сообщения о точности). */
    private static function pointsToMeters(float $px, callable $toFloor): float
    {
        $a = $toFloor(0.0, 0.0);
        $b = $toFloor($px, 0.0);

        return hypot($b[0] - $a[0], $b[1] - $a[1]);
    }
}
