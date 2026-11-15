<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Office;
use His\OnecOdata\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Справочник основных средств из 1С – зеркало, как филиалы и сотрудники из HR (hr:sync):
 * руками эти поля не правят. 1С первична по учету, plan – по месту.
 *
 * Из 1С перетираем только учетное: ключ ОС, наименование по учету, инвентарный и заводской
 * номер, стоимость, дату принятия к учету, подразделение, списание. Место и закрепление
 * (филиал, этаж, помещение, точка, МОЛ, пользователь) не трогаем НИКОГДА – их меняет
 * только AssetMover, а расхождение показывает страница "Сверка с 1С".
 *
 * Чего нет в plan – заводим нерасставленной единицей филиала (филиал – по подразделению 1С,
 * offices.onec_department_ref; категория – по словам названия, как в ProjectImport).
 * Пропавших из ответа не удаляем: к единице привязаны история передач, ТО и поверки.
 * Пустой ответ – сбой, а не "все списали": на нем не пишем ничего.
 *
 * Имена справочника и реквизитов 1С – в config/onec.php, в коде их нет. Не заданный там
 * реквизит не запрашивается и не зеркалится: $select с несуществующим именем 1С отклоняет
 * целиком (HTTP 400), поэтому угадывать нельзя – синк включается по частям, по мере того
 * как имена проверяют на базе (`php artisan onec:probe`). Остальные грабли платформы – в
 * README пакета his/onec-odata: $orderby=Ref_Key при обходе, кодирование по RFC 3986,
 * trim() всего строкового, пустая дата как 0001-01-01, папки и помеченные на удаление
 * отсеивать у себя (Client::live), Code не уникален – ключ только Ref_Key.
 */
class OnecSync
{
    /** Строк за один запрос: в справочнике ОС тысячи карточек. */
    private const PAGE = 500;

    public function __construct(private ?Client $client = null) {}

    /**
     * Прочитать 1С и сверить с plan, ничего не записывая. То же считает страница сверки и
     * onec:sync --dry-run – чтобы показанное и записанное не разошлись.
     *
     * @param  int|null  $officeId  только этот филиал
     */
    public function compare(?int $officeId = null): array
    {
        $fields = self::fields();
        $rows = $this->rows($fields);

        // Пустой ответ при живом соединении – скорее сбой публикации, чем пустой справочник.
        if ($rows === []) {
            throw new RuntimeException('1С вернула пустой справочник основных средств. Ничего не меняем: это похоже на сбой, а не на то, что ОС не осталось.');
        }

        $folders = $this->folders($rows, $fields);
        $live = Client::live($rows);

        $offices = Office::whereNotNull('onec_department_ref')->pluck('id', 'onec_department_ref');
        $assets = Asset::with(['mol', 'office', 'room', 'category'])->get();
        $knownRefs = Asset::withTrashed()->whereNotNull('accounting_ref')->pluck('accounting_ref')->flip();
        $byRef = $assets->whereNotNull('accounting_ref')->keyBy('accounting_ref');
        $byInventory = $assets->whereNotNull('inventory_number')->keyBy('inventory_number');
        $bySerial = $assets->whereNotNull('serial_number')->keyBy('serial_number');
        $categories = Category::orderBy('sort')->get();

        $report = [
            'rows' => count($rows), 'live' => count($live), 'skipped' => 0, 'matched' => 0,
            'create' => [], 'no_office' => [], 'accounting' => [], 'placement' => [],
            'written_off' => [], 'conflicts' => [],
        ];
        $claimed = [];
        $seen = [];

        foreach ($live as $row) {
            $card = $this->card($row, $fields);

            if ($card['ref'] === '' || $this->skipped($row, $fields, $folders)) {
                $report['skipped']++;

                continue;
            }

            $seen[$card['ref']] = true;
            $office = $card['department_ref'] === null ? null : ($offices[$card['department_ref']] ?? null);

            // Ключ ОС, затем инвентарный номер, затем заводской. По Code не ищем: он в 1С
            // не уникален (README пакета), и совпадение было бы случайным.
            $asset = $byRef[$card['ref']]
                ?? ($card['inventory_number'] !== null ? $byInventory[$card['inventory_number']] ?? null : null)
                ?? ($card['serial_number'] !== null ? $bySerial[$card['serial_number']] ?? null : null);

            // Одна единица plan – одна карточка 1С: на вторую такую же единица не отдается
            // (инвентарный номер в справочнике ОС бывает и пустым, и повторенным).
            if ($asset && isset($claimed[$asset->id])) {
                $asset = null;
            }

            if ($asset) {
                if ($officeId !== null && (int) $asset->office_id !== $officeId) {
                    continue;
                }
                $claimed[$asset->id] = true;
                $report['matched']++;
                $this->diff($asset, $card, $office, $report);

                continue;
            }

            if ($officeId !== null && $office !== $officeId) {
                continue;
            }
            // Единицу уже заводили и удалили – заново не создаем.
            if (isset($knownRefs[$card['ref']])) {
                continue;
            }

            $card['office_id'] = $office;
            $card['category'] = ProjectImport::guessCategory($card['name'], $categories) ?? self::other($categories);

            // Без филиала (подразделение 1С не сопоставлено) единицу не завести: филиал у нее
            // обязателен. Такие показываем списком – это и есть "есть только в 1С".
            if ($card['office_id'] === null || $card['category'] === null) {
                $report['no_office'][] = $card;

                continue;
            }

            $report['create'][] = $card;
        }

        $plan = $officeId === null ? $assets : $assets->where('office_id', $officeId);
        // Без ключа ОС строка акта в 1С не уйдет: Transfers::issue берет только то, что там
        // учитывается – с ключом ОС или инвентарным номером.
        $report['no_ref'] = $plan->filter(fn (Asset $a) => $a->accounting_ref === null && $a->written_off_at === null)->values();
        $report['missing'] = $plan->filter(fn (Asset $a) => $a->accounting_ref !== null && ! isset($seen[$a->accounting_ref]))->values();

        return $report;
    }

    /**
     * Сверить и записать учетные поля. Место и МОЛ не пишем – только показываем расхождение.
     *
     * @return array отчет compare() плюс 'applied' => [created, updated, written_off]
     */
    public function run(?int $officeId = null): array
    {
        $report = $this->compare($officeId);

        $applied = DB::transaction(function () use ($report) {
            $created = 0;
            foreach ($report['create'] as $card) {
                // Новая единица еще нигде не стоит: место ей задаст AssetMover, когда ее
                // поставят на план. Филиал при заведении – как в импорте из проекта.
                Asset::create(self::accounting($card) + array_filter([
                    'category_id' => $card['category']->id,
                    'office_id' => $card['office_id'],
                    'name' => $card['name'],
                    'department' => $card['department_name'],
                ], fn ($v) => $v !== null) + [
                    'status' => $card['written_off'] ? AssetStatus::WrittenOff : AssetStatus::InUse,
                    'written_off_at' => $card['written_off'] ? ($card['written_off_at'] ?? today()->toDateString()) : null,
                ]);
                $created++;
            }

            $updated = 0;
            foreach ($report['accounting'] as $line) {
                $asset = $line['asset'];
                // Страховка от собственной ошибки: место и закрепление сюда не попадают.
                $asset->fill(Arr::except($line['write'], Asset::PLACEMENT));
                if ($asset->isDirty()) {
                    $asset->save();
                    $updated++;
                }
            }

            $writtenOff = 0;
            foreach ($report['written_off'] as $line) {
                $line['asset']->update(['written_off_at' => $line['date'], 'status' => AssetStatus::WrittenOff]);
                $writtenOff++;
            }

            return ['created' => $created, 'updated' => $updated, 'written_off' => $writtenOff];
        });

        Audit::log('onec_sync', 0, 'synced', $applied + [
            'карточек в 1С' => $report['live'],
            'совпало' => $report['matched'],
            'без филиала' => count($report['no_office']),
        ]);

        return $report + ['applied' => $applied];
    }

    /**
     * Состав реквизитов справочника по одной карточке: имена видно сразу, и их остается
     * вписать в config/onec.php. $metadata не разбираем – одной записи достаточно.
     *
     * @return array<string, string> реквизит => значение первой карточки
     */
    public function probe(?string $catalog = null): array
    {
        $rows = $this->client()->get($catalog ?: self::catalog(), ['top' => 1]);

        if ($rows === []) {
            return [];
        }

        return array_map(
            fn ($v) => is_scalar($v) || $v === null ? trim((string) $v) : json_encode($v, JSON_UNESCAPED_UNICODE),
            $rows[0]
        );
    }

    /** Расхождения совпавшей единицы: учетные поля (их перетираем) и место с МОЛ (не трогаем). */
    private function diff(Asset $asset, array $card, ?int $office, array &$report): void
    {
        $write = self::accounting($card);

        if ($card['department_name'] !== null) {
            $write['department'] = $card['department_name'];
        }
        // Заводской номер в 1С заполняют не всегда – известный свой не перетираем.
        if ($asset->serial_number !== null) {
            unset($write['serial_number']);
        }

        // Инвентарный номер уникален: занятый другой единицей не пишем – это разбирают руками.
        if (isset($write['inventory_number'])) {
            $taken = Asset::withTrashed()->where('inventory_number', $write['inventory_number'])
                ->whereKeyNot($asset->id)->first();
            if ($taken) {
                $report['conflicts'][] = ['asset' => $asset, 'other' => $taken, 'inventory_number' => $write['inventory_number']];
                unset($write['inventory_number']);
            }
        }

        $changed = [];
        foreach ($write as $field => $value) {
            $was = $asset->getAttribute($field);
            $was = $was instanceof \DateTimeInterface ? $was->format('Y-m-d') : $was;
            // Стоимость – число: "0" и "0.00" это одно и то же, а не расхождение.
            $same = $field === 'price'
                ? $was !== null && abs((float) $was - (float) $value) < 0.005
                : (string) $was === (string) $value;
            if (! $same) {
                $changed[$field] = [$was, $value];
            }
        }
        if ($changed) {
            $report['accounting'][] = ['asset' => $asset, 'diff' => $changed, 'write' => $write];
        }

        if ($card['written_off'] && $asset->written_off_at === null) {
            $report['written_off'][] = ['asset' => $asset, 'date' => $card['written_off_at'] ?? today()->toDateString()];
        }

        $place = [];
        if ($office !== null && (int) $asset->office_id !== $office) {
            $place['Филиал'] = [$asset->office?->display_name, Office::find($office)?->display_name];
        }
        if ($card['mol_name'] !== null && $card['mol_name'] !== (string) $asset->mol?->full_name) {
            $place['МОЛ'] = [$asset->mol?->full_name, $card['mol_name']];
        }
        if (! $card['written_off'] && $asset->written_off_at !== null) {
            $place['Списание'] = ['списано '.$asset->written_off_at->format('d.m.Y'), 'в 1С не списано'];
        }
        if ($place) {
            $report['placement'][] = ['asset' => $asset, 'diff' => $place];
        }
    }

    /** Учетные поля карточки – то, что пишем в единицу. Незаданных реквизитов здесь нет. */
    private static function accounting(array $card): array
    {
        return array_filter([
            'accounting_ref' => $card['ref'],
            'accounting_name' => $card['accounting_name'],
            'inventory_number' => $card['inventory_number'],
            'serial_number' => $card['serial_number'],
            'price' => $card['price'],
            'commissioned_at' => $card['commissioned_at'],
        ], fn ($v) => $v !== null);
    }

    /** Карточка 1С в наших именах: строковое – через trim, пустая дата – null. */
    private function card(array $row, array $fields): array
    {
        $raw = function (string $key) use ($row, $fields) {
            $name = $fields[$key] ?? '';

            return $name !== '' && array_key_exists($name, $row) ? $row[$name] : null;
        };
        // Хвостовые пробелы: Code и прочие строки фиксированной длины приезжают как "778    ".
        $text = fn (string $key) => ($v = $raw($key)) === null ? null : (trim((string) $v) ?: null);
        $price = $raw('price');

        return [
            'ref' => trim((string) ($raw('ref') ?? '')),
            'code' => $text('code'),
            'name' => $text('accounting_name') ?? $text('name') ?? 'Основное средство',
            'accounting_name' => $text('accounting_name') ?? $text('name'),
            'inventory_number' => $text('inventory_number'),
            'serial_number' => $text('serial_number'),
            'price' => $price === null || $price === '' ? null : (float) $price,
            'commissioned_at' => self::date($raw('commissioned_at')),
            'department_ref' => self::ref($raw('department_ref')),
            'department_name' => $text('department_name'),
            'mol_name' => $text('mol_name'),
            'written_off' => (bool) $raw('written_off'),
            'written_off_at' => self::date($raw('written_off_at')),
        ];
    }

    /** Пустую дату 1С отдает началом эпохи – для нас это "не заполнено". */
    private static function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || str_starts_with($value, '0001-01-01')) {
            return null;
        }

        return substr($value, 0, 10);
    }

    /** Незаполненная ссылка 1С – нулевой GUID, а не пустая строка. */
    private static function ref(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || $value === '00000000-0000-0000-0000-000000000000' ? null : $value;
    }

    /**
     * Папки справочника: ключ => [название, родитель]. Нужны, чтобы отсеять то, что к
     * имуществу филиалов не относится (здания, автотранспорт).
     */
    private function folders(array $rows, array $fields): array
    {
        if (($fields['folder'] ?? '') === '') {
            return [];
        }

        $folders = [];
        foreach ($rows as $row) {
            if (empty($row[$fields['folder']] ?? null)) {
                continue;
            }
            $ref = self::ref($row[$fields['ref']] ?? null);
            if ($ref !== null) {
                $folders[$ref] = [
                    'name' => trim((string) ($row[$fields['name']] ?? '')),
                    'parent' => self::ref($row[$fields['parent']] ?? null),
                ];
            }
        }

        return $folders;
    }

    /** Карточка лежит в папке, которую не берем? */
    private function skipped(array $row, array $fields, array $folders): bool
    {
        $skip = array_map('mb_strtolower', array_filter((array) config('onec.skip_folders', [])));
        if ($skip === [] || $folders === [] || ($fields['parent'] ?? '') === '') {
            return false;
        }

        $parent = self::ref($row[$fields['parent']] ?? null);
        // Папки вложены неглубоко, но предел шагов дешевле, чем цикл в данных.
        for ($depth = 0; $parent !== null && $depth < 10; $depth++) {
            $folder = $folders[$parent] ?? null;
            if ($folder === null) {
                return false;
            }
            if (in_array(mb_strtolower($folder['name']), $skip, true)) {
                return true;
            }
            $parent = $folder['parent'];
        }

        return false;
    }

    /** @return array<int, array<string, mixed>> весь справочник, страницами */
    private function rows(array $fields): array
    {
        // $select – только заданные реквизиты: запрос несуществующего 1С отклоняет целиком.
        return $this->client()->getAll(self::catalog(), [
            'select' => implode(',', array_values($fields)),
        ], self::PAGE);
    }

    private static function catalog(): string
    {
        return (string) config('onec.catalog');
    }

    /** @return array<string, string> реквизит => имя в 1С, без незаданных */
    private static function fields(): array
    {
        return array_filter((array) config('onec.fields'), fn ($name) => trim((string) $name) !== '');
    }

    /** Категория для того, что не подошло по названию: "Прочее ..." – как в импорте из проекта. */
    private static function other(Collection $categories): ?Category
    {
        return $categories->first(fn (Category $c) => str_starts_with($c->name, 'Проч')) ?? $categories->first();
    }

    /** Клиент из контейнера, если он там связан (в тестах), иначе – по реквизитам из настроек. */
    private function client(): Client
    {
        if ($this->client) {
            return $this->client;
        }
        if (app()->bound(Client::class)) {
            return app(Client::class);
        }

        $cfg = config('services.onec');
        if (! $cfg['base_url'] || ! $cfg['username']) {
            throw new RuntimeException('Не заданы реквизиты 1С (Настройки – 1С).');
        }

        return $this->client = new Client($cfg['base_url'], $cfg['username'], (string) $cfg['password'], (int) $cfg['timeout']);
    }
}
