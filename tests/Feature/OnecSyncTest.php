<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Layer;
use App\Models\Office;
use App\Models\User;
use App\Services\OnecSync;
use His\OnecOdata\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Зеркало справочника основных средств 1С.
 *
 * Ответ 1С берется из фикстуры tests/Fixtures/onec_assets.json – тела OData как есть, со
 * всеми особенностями платформы: хвостовые пробелы, пустая дата как 0001-01-01, папки и
 * помеченные на удаление вперемешку с карточками, нулевой GUID вместо пустой ссылки.
 *
 * Клиент подменяется в контейнере, а не через Http::fake: пакет his/onec-odata ходит curl
 * напрямую, и фасад Http его запросов не видит (так же сделано в supply).
 *
 * ИМЕНА РЕКВИЗИТОВ В ФИКСТУРЕ – ВЫДУМАННЫЕ. Настоящие берутся с базы 1С и вписываются в
 * config/onec.php (`php artisan onec:probe`); тесты проверяют разбор и правила зеркала, а
 * не состав метаданных.
 */
class OnecSyncTest extends TestCase
{
    use RefreshDatabase;

    private const FIELDS = [
        'ref' => 'Ref_Key',
        'code' => 'Code',
        'name' => 'Description',
        'folder' => 'IsFolder',
        'parent' => 'Parent_Key',
        'deleted' => 'DeletionMark',
        'inventory_number' => 'ИнвентарныйНомер',
        'accounting_name' => 'НаименованиеПолное',
        'serial_number' => 'ЗаводскойНомер',
        'price' => 'Стоимость',
        'commissioned_at' => 'ДатаПринятияКУчету',
        'department_ref' => 'Подразделение_Key',
        'department_name' => '',
        'mol_name' => 'МатериальноОтветственноеЛицо',
        'written_off' => 'Снят',
        'written_off_at' => 'ДатаСнятияСУчета',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['onec.fields' => self::FIELDS, 'onec.skip_folders' => ['Здания']]);

        $layer = Layer::create(['code' => 'lab', 'name' => 'Лабораторное оборудование']);
        foreach (['Анализаторы', 'Микроскопы', 'Мониторы и аппараты ИВЛ', 'Прочее лабораторное оборудование'] as $i => $name) {
            Category::create(['layer_id' => $layer->id, 'name' => $name, 'sort' => ($i + 1) * 10]);
        }
    }

    /** @return array<int, array<string, mixed>> value из фикстуры ответа OData */
    private function fixture(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/onec_assets.json'), true)['value'];
    }

    /** Подменить клиент 1С. Возвращает его же – из него видно, что ушло в $select. */
    private function onec(?array $rows = null): Client
    {
        $client = new class('http://1c.local', 'u', 'p', rows: $rows ?? $this->fixture()) extends Client
        {
            public array $queries = [];

            public function __construct(string $base, string $user, string $pass, public array $rows = [])
            {
                parent::__construct($base, $user, $pass);
            }

            public function getAll(string $entity, array $query = [], int $pageSize = self::DEFAULT_PAGE_SIZE): array
            {
                $this->queries[] = $query;

                return $this->rows;
            }

            public function get(string $entity, array $query = []): array
            {
                $this->queries[] = $query;

                return array_slice($this->rows, 0, (int) ($query['top'] ?? 1));
            }
        };

        $this->app->instance(Client::class, $client);

        return $client;
    }

    /** Филиал с сопоставленным подразделением 1С. */
    private function office(string $department = 'dep-almaty'): Office
    {
        $office = $this->room()->floor->building->office;
        $office->update(['onec_department_ref' => $department]);

        return $office;
    }

    public function test_первый_обход_заводит_единицы_с_учетными_полями(): void
    {
        $office = $this->office();
        $this->onec();

        $report = app(OnecSync::class)->run();

        // Шесть живых карточек из девяти строк: две папки и одну помеченную на удаление
        // отсеял Client::live. Заводим три – остальные в чужой папке или без филиала.
        $this->assertSame(9, $report['rows']);
        $this->assertSame(6, $report['live']);
        $this->assertSame(3, $report['applied']['created']);

        $analyzer = Asset::where('accounting_ref', 'a-1')->sole();
        // Хвостовые пробелы обрезаны: иначе сравнение с сохраненным молча не срабатывает.
        $this->assertSame('00123', $analyzer->inventory_number);
        $this->assertSame('Анализатор биохимический AU480', $analyzer->accounting_name);
        $this->assertSame('SN-480-17', $analyzer->serial_number);
        $this->assertSame('15400000.55', $analyzer->price);
        $this->assertSame('2024-05-20', $analyzer->commissioned_at->toDateString());
        $this->assertSame($office->id, $analyzer->office_id);
        // Категория – по словам названия, как в импорте из проекта.
        $this->assertSame('Анализаторы', $analyzer->category->name);
        // Новая единица нигде не стоит: место ей задаст AssetMover.
        $this->assertFalse($analyzer->isPlaced());

        $microscope = Asset::where('accounting_ref', 'a-2')->sole();
        // Пустая дата приходит как 0001-01-01 – это "не заполнено", а не первый год эры.
        $this->assertNull($microscope->commissioned_at);
        $this->assertNull($microscope->price);
        $this->assertNull($microscope->serial_number);
        // Полного наименования нет – берем Description.
        $this->assertSame('Микроскоп Levenhuk 740T', $microscope->accounting_name);

        // Папка и помеченная на удаление – не единицы.
        $this->assertNull(Asset::where('accounting_ref', 'f-med')->first());
        $this->assertNull(Asset::where('accounting_ref', 'a-4')->first());
        // Квартира лежит в папке "Здания": имущество филиалов это не.
        $this->assertNull(Asset::where('accounting_ref', 'a-3')->first());
    }

    public function test_повторный_запуск_не_плодит_единицы(): void
    {
        $this->office();
        $this->onec();
        $sync = app(OnecSync::class);

        $sync->run();
        $count = Asset::count();
        $second = $sync->run();

        $this->assertSame(3, $count);
        $this->assertSame($count, Asset::count());
        $this->assertSame(0, $second['applied']['created']);
        $this->assertSame(0, $second['applied']['updated']);
        // Все найденное – по ключу ОС, который поставили в первый раз.
        $this->assertSame(3, $second['matched']);
    }

    public function test_место_и_мол_не_перетираются_а_расхождение_видно(): void
    {
        $office = $this->office();
        $room = $office->buildings->first()->floors->first()->rooms->first();
        $mol = Employee::create(['hr_id' => 70, 'full_name' => 'Сидоров Сидор Сидорович']);
        $other = Office::create(['hr_id' => 2, 'name' => 'Филиал 2', 'onec_department_ref' => 'dep-shymkent']);

        $asset = Asset::create([
            'category_id' => Category::where('name', 'Анализаторы')->value('id'),
            'office_id' => $other->id,
            'room_id' => $room->id,
            'floor_id' => $room->floor_id,
            'x' => 1.5, 'y' => 2.5,
            'mol_id' => $mol->id,
            'accounting_ref' => 'a-1',
            'name' => 'Анализатор в лаборатории',
        ]);

        $this->onec();
        $report = app(OnecSync::class)->run();

        $asset->refresh();
        // 1С числит его в другом подразделении и за другим МОЛ – не наше дело: место и
        // закрепление меняет только AssetMover (акт передачи).
        $this->assertSame($other->id, $asset->office_id);
        $this->assertSame($room->id, $asset->room_id);
        $this->assertSame($mol->id, $asset->mol_id);
        $this->assertSame(1.5, $asset->x);
        // Свое название тоже цело – наименование по учету лежит отдельным полем.
        $this->assertSame('Анализатор в лаборатории', $asset->name);
        $this->assertSame('Анализатор биохимический AU480', $asset->accounting_name);

        $diff = collect($report['placement'])->firstWhere('asset.id', $asset->id);
        $this->assertNotNull($diff);
        $this->assertArrayHasKey('Филиал', $diff['diff']);
        $this->assertArrayHasKey('МОЛ', $diff['diff']);
    }

    public function test_сопоставление_по_инвентарному_и_заводскому_номеру(): void
    {
        $office = $this->office();
        $category = Category::where('name', 'Микроскопы')->value('id');

        $byInventory = Asset::create(['category_id' => $category, 'office_id' => $office->id,
            'name' => 'Микроскоп', 'inventory_number' => '00124']);
        $bySerial = Asset::create(['category_id' => $category, 'office_id' => $office->id,
            'name' => 'Анализатор', 'serial_number' => 'SN-480-17']);

        $this->onec();
        app(OnecSync::class)->run();

        // Ключ ОС встал на обе – вторыми единицами они не завелись.
        $this->assertSame('a-2', $byInventory->fresh()->accounting_ref);
        $this->assertSame('a-1', $bySerial->fresh()->accounting_ref);
        $this->assertSame('00123', $bySerial->fresh()->inventory_number);
        $this->assertSame(2, Asset::whereIn('accounting_ref', ['a-1', 'a-2'])->count());
    }

    public function test_списанное_в_1с_закрывает_единицу(): void
    {
        $office = $this->office();
        $working = Asset::create(['category_id' => Category::first()->id, 'office_id' => $office->id,
            'name' => 'Аппарат ИВЛ', 'accounting_ref' => 'a-7']);

        $this->onec();
        $report = app(OnecSync::class)->run();

        $this->assertSame(1, $report['applied']['written_off']);
        $working->refresh();
        $this->assertSame('2026-09-01', $working->written_off_at->toDateString());
        $this->assertSame(AssetStatus::WrittenOff, $working->status);

        // Заведенное списанным – сразу с датой и статусом, без второго прохода.
        $this->assertSame(AssetStatus::WrittenOff, Asset::where('accounting_ref', 'a-7')->sole()->status);
    }

    public function test_без_сопоставленного_подразделения_единицу_не_заводим(): void
    {
        $this->office();
        $this->onec();

        $report = app(OnecSync::class)->run();

        // Стол без подразделения (нулевой GUID) и холодильник чужого подразделения – завести
        // их некуда: филиал у единицы обязателен. Показываем списком, в 1С не лезем.
        $this->assertCount(2, $report['no_office']);
        $this->assertSame(['a-5', 'a-6'], array_column($report['no_office'], 'ref'));
        $this->assertNull(Asset::where('accounting_ref', 'a-5')->first());
    }

    public function test_пустой_ответ_не_списывает_все(): void
    {
        $office = $this->office();
        $asset = Asset::create(['category_id' => Category::first()->id, 'office_id' => $office->id,
            'name' => 'Анализатор', 'accounting_ref' => 'a-1']);

        $this->onec([]);

        try {
            app(OnecSync::class)->run();
            $this->fail('Пустой ответ 1С должен останавливать синхронизацию.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('пустой справочник', $e->getMessage());
        }

        // Пустой ответ – сбой публикации, а не "все списали".
        $this->assertNull($asset->fresh()->written_off_at);
        $this->assertSame(1, Asset::count());
    }

    public function test_незаданные_реквизиты_не_запрашиваются(): void
    {
        $this->office();
        // Проверять на базе еще не успели – имена пустые, значит этих полей нет в $select:
        // $select с несуществующим реквизитом 1С отклоняет целиком (HTTP 400).
        config(['onec.fields' => ['ref' => 'Ref_Key', 'name' => 'Description', 'folder' => 'IsFolder',
            'parent' => 'Parent_Key', 'deleted' => 'DeletionMark', 'department_ref' => 'Подразделение_Key',
            'inventory_number' => '', 'price' => '', 'written_off' => '']]);

        $client = $this->onec();
        $report = app(OnecSync::class)->run();

        $select = $client->queries[0]['select'];
        $this->assertSame('Ref_Key,Description,IsFolder,Parent_Key,DeletionMark,Подразделение_Key', $select);
        // Синк все равно работает – просто зеркалит меньше.
        $this->assertSame(3, $report['applied']['created']);
        $this->assertNull(Asset::where('accounting_ref', 'a-1')->sole()->inventory_number);
    }

    public function test_dry_run_ничего_не_пишет(): void
    {
        $this->office();
        $this->onec();

        $this->artisan('onec:sync', ['--dry-run' => true])
            ->expectsOutputToContain('Будет заведено: 3')
            ->expectsOutputToContain('ничего не записано')
            ->assertSuccessful();

        $this->assertSame(0, Asset::count());
    }

    public function test_команда_зеркалит_и_ругается_на_чужой_филиал(): void
    {
        $this->office();
        $this->onec();

        $this->artisan('onec:sync')->expectsOutputToContain('Заведено 3')->assertSuccessful();
        $this->assertSame(3, Asset::count());

        $this->artisan('onec:sync', ['--office' => 9999])->assertFailed();
    }

    public function test_страница_сверки_показывает_расхождения_и_правит_сопоставление(): void
    {
        // Подразделение 1С не сопоставлено: заводить некуда, и это видно на странице.
        $office = $this->room()->floor->building->office;
        $this->onec();
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        $this->get('/onec')
            ->assertOk()
            ->assertSee('Есть только в 1С')
            ->assertSee('Расхождения по месту и МОЛ')
            // Подразделение не сопоставлено – заводить нельзя, и это видно.
            ->assertSee('Анализатор биохимический AU480');

        $this->put('/onec/offices', ['departments' => [$office->id => ' dep-almaty ']])
            ->assertRedirect();

        $this->assertSame('dep-almaty', $office->fresh()->onec_department_ref);
        // Страница только показывает: единиц она не заводит.
        $this->assertSame(0, Asset::count());
    }

    public function test_сверка_без_реквизитов_1с_не_падает(): void
    {
        config(['services.onec.base_url' => null, 'services.onec.username' => null]);
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        $this->get('/onec')->assertOk()->assertSee('Не заданы реквизиты 1С');
    }
}
