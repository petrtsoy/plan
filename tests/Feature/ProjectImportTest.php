<?php

namespace Tests\Feature;

use App\Enums\PlanSource;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Layer;
use App\Models\Plan;
use App\Models\User;
use App\Services\ProjectImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Импорт из рабочего проекта на синтетических листах: подложка (лист 1) с номерами помещений
 * и экспликацией, лист расстановки (2) – тот же план вдвое мельче и со сдвигом, с площадями
 * и метками, спецификация (3).
 */
class ProjectImportTest extends TestCase
{
    use RefreshDatabase;

    /** Слово с центром в (x, y): 3 точки на букву, высота 5. */
    private static function w(string $t, float $x, float $y): array
    {
        $half = 1.5 * mb_strlen($t);

        return [$t, $x - $half, $y - 2.5, $x + $half, $y + 2.5];
    }

    /** Экспликация с левым верхним углом (x, y). */
    private static function roomTable(float $x, float $y): array
    {
        $w = fn ($t, $dx, $dy) => self::w($t, $x + $dx, $y + $dy);

        return [
            $w('Номер', 30, 0), $w('Имя', 80, 0), $w('Площадь', 140, 0),
            $w('101', 30, 10), $w('Процедурная', 70, 10), $w('12,50', 135, 10),
            $w('102', 30, 20), $w('Склад', 70, 20), $w('6,00', 135, 20),
            // Название в две строки: вторая строка – к своей строке, а не к следующей.
            $w('103', 30, 30), $w('Кабинет', 70, 30), $w('10,00', 135, 30), $w('врача', 70, 35.5),
            $w('104', 30, 42), $w('Касса', 70, 42), $w('8,00', 135, 42),
            $w('Общий', 30, 55), $w('итог:', 55, 55), $w('4', 70, 55),
        ];
    }

    public static function pages(): array
    {
        // Метки помещений на подложке – в углах квадрата 100 x 100.
        $base = ['w' => 420, 'h' => 300, 'words' => array_merge([
            self::w('101', 50, 50), self::w('102', 150, 50), self::w('103', 50, 150), self::w('104', 150, 150),
        ], self::roomTable(200, 200))];
        // Лист расстановки: точка подложки = 2 * точка листа + (10, 5).
        $at = fn ($x, $y) => [($x - 10) / 2, ($y - 5) / 2];
        $marks = ['w' => 420, 'h' => 300, 'words' => array_merge([
            self::w('12,50', ...$at(50, 50)), self::w('6,00', ...$at(150, 50)),
            self::w('10,00', ...$at(50, 150)), self::w('8,00', ...$at(150, 150)),
            self::w('М1', 30, 30), self::w('Э1', 60, 60),
            // Легенда вдали от плана – не метка.
            self::w('М1', 400, 280), self::w('Мебель', 410, 280),
        ], self::roomTable(250, 150))];
        $spec = ['w' => 420, 'h' => 300, 'words' => [
            self::w('Спецификация', 30, 8), self::w('мебели', 70, 8), self::w('1-го', 90, 8), self::w('этажа', 105, 8),
            self::w('№', 15, 20), self::w('Наименование', 50, 20), self::w('Кол-во', 110, 20), self::w('Помещение', 150, 20),
            self::w('М1', 15, 30), self::w('Стул', 40, 30), self::w('2', 110, 30), self::w('Процедурная', 140, 30), self::w('101', 175, 30), self::w('6МО6', 200, 30),
            self::w('Э1', 15, 40), self::w('Холодильник', 50, 40), self::w('1', 110, 40), self::w('Склад', 140, 40), self::w('102', 175, 40),
        ]];

        return [1 => $base, 2 => $marks, 3 => $spec];
    }

    public function test_tables_labels_and_marks_from_another_sheet(): void
    {
        // Картинка подложки 4200 x 3000: 10 пикселей на точку листа.
        $r = ProjectImport::build(self::pages(), 1, 2, 3, 4200, 3000, fn ($x, $y) => [$x, $y]);

        $rooms = collect($r['rooms'])->keyBy('number');
        $this->assertSame(['101', '102', '103', '104'], $rooms->pluck('number')->all());
        $this->assertSame('Кабинет врача', $rooms['103']['name']);
        $this->assertEquals(10.0, $rooms['103']['area']);
        $this->assertEquals([500, 500], $rooms['101']['label']);

        $items = collect($r['items'])->keyBy('mark');
        $this->assertCount(2, $items, 'легенда – не метка');
        $this->assertSame(['Стул', '101', '6МО6'], [$items['М1']['name'], $items['М1']['room'], $items['М1']['catalog']]);
        $this->assertEqualsWithDelta(700, $items['М1']['x'], 0.5);
        $this->assertEqualsWithDelta(650, $items['М1']['y'], 0.5);
        $this->assertEqualsWithDelta(1300, $items['Э1']['x'], 0.5);
        $this->assertSame(['мебели' => ['title' => 'Мебели', 'count' => 2]], $r['groups']);
    }

    public function test_category_guessed_by_name_within_layer(): void
    {
        $layer = Layer::create(['code' => 'furniture', 'name' => 'Мебель']);
        $cats = collect(['Столы', 'Стулья и кресла', 'Шкафы и стеллажи'])->map(fn ($n) => Category::create(['layer_id' => $layer->id, 'name' => $n]));

        $this->assertSame('Стулья и кресла', ProjectImport::guessCategory('Офисное кресло', $cats)->name);
        $this->assertSame('Шкафы и стеллажи', ProjectImport::guessCategory('Стеллаж 1000*600*1950', $cats)->name);
        $this->assertNull(ProjectImport::guessCategory('Холодильник', $cats));

        $gases = Category::create(['layer_id' => $layer->id, 'name' => 'Точки медицинских газов']);
        $this->assertNull(ProjectImport::guessCategory('Тележка медицинская', collect([$gases])), 'общие слова не в счет');
    }

    public function test_preview_then_import_without_duplicates(): void
    {
        $room = $this->room();
        $floor = $room->floor;
        Plan::create(['floor_id' => $floor->id, 'source' => PlanSource::Pdf, 'source_path' => 'plans/x.pdf', 'source_name' => 'проект.pdf',
            'image_path' => 'plans/x.png', 'width_px' => 4200, 'height_px' => 3000, 'page' => 1]);
        $layer = Layer::create(['code' => 'furniture', 'name' => 'Мебель']);
        $tables = Category::create(['layer_id' => $layer->id, 'name' => 'Столы']);
        $chairs = Category::create(['layer_id' => $layer->id, 'name' => 'Стулья и кресла']);
        $this->app->instance(ProjectImport::class, new class extends ProjectImport
        {
            public function words(string $pdfPath, int $page): array
            {
                return ProjectImportTest::pages()[$page];
            }
        });
        $this->actingAs(User::factory()->role(Role::Engineer)->create());
        $pages = ['marks_page' => 2, 'spec_page' => 3];

        $this->postJson("/floors/{$floor->id}/import", $pages)->assertOk()
            ->assertJsonCount(4, 'rooms')->assertJsonCount(2, 'items')
            ->assertJsonPath('suggest.мебели', $tables->id);
        $this->assertSame(1, $floor->rooms()->count(), 'просмотр ничего не создает');

        for ($i = 0; $i < 2; $i++) {
            $this->postJson("/floors/{$floor->id}/import", $pages + ['confirm' => true, 'categories' => ['мебели' => $tables->id]])->assertOk();
        }

        $this->assertSame(4, $floor->rooms()->count());
        $this->assertSame('Лаборатория', $room->fresh()->name, 'существующее помещение не переименовано');
        $this->assertEquals([500, 500], [$room->fresh()->label_x, $room->fresh()->label_y]);
        $this->assertEquals(6.0, (float) $floor->rooms()->where('number', '102')->value('area_m2'));

        $assets = Asset::where('floor_id', $floor->id)->get()->keyBy('name');
        $this->assertCount(2, $assets, 'повторный импорт не дублирует');
        $this->assertSame($chairs->id, $assets['Стул']->category_id);
        $this->assertSame($tables->id, $assets['Холодильник']->category_id);
        $this->assertSame($room->id, $assets['Стул']->room_id);
        $this->assertSame(1, $assets['Стул']->moves()->count());

        $this->get("/floors/{$floor->id}")->assertOk()
            ->assertViewHas('map', fn ($map) => $map['pending']->pluck('number')->sort()->values()->all() === ['102', '103', '104']);
    }
}
