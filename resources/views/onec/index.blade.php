@extends('layouts.app')
@section('title', 'Сверка с 1С')
@section('content')
@php($canAssets = auth()->user()->edits(\App\Enums\Area::Assets))
<h1>Сверка с 1С</h1>
<p class="muted small">
    1С первична по учету, plan – по месту. Ночной <code>onec:sync</code> перетирает только учетные
    поля; филиал, помещение, точку на плане и МОЛ он не меняет никогда – расхождение по ним просто
    видно здесь. Эта страница тоже ничего не правит: она читает 1С заново при каждом открытии.
</p>

@if($error)
    <div class="panel">
        <p class="flash error">{{ $error }}</p>
        <p class="muted small">
            Реквизиты подключения – "Настройки" – "1С". Имена справочника и реквизитов –
            <code>config/onec.php</code>; что еще не проверено на базе, там пусто и не запрашивается
            (<code>php artisan onec:probe</code> покажет состав реквизитов).
        </p>
    </div>
@else
    <div class="panel">
        <p>
            В 1С строк: <b>{{ $report['rows'] }}</b>, живых карточек <b>{{ $report['live'] }}</b>
            (папки и помеченные на удаление отсеяны), отсеяно папками <b>{{ $report['skipped'] }}</b>.
            Совпало с plan: <b>{{ $report['matched'] }}</b>.
        </p>
    </div>

    <h2>Есть только в 1С</h2>
    @if($report['create'] === [] && $report['no_office'] === [])
        <p class="muted small">Таких нет.</p>
    @else
        @if($report['create'])
            <p class="muted small">Будут заведены нерасставленными единицами филиала при следующем <code>onec:sync</code>.</p>
            <div class="table-wrap"><table>
                <tr><th>Наименование по учету</th><th>Инвентарный</th><th>Филиал</th><th>Категория</th><th>Принято к учету</th></tr>
                @foreach($report['create'] as $card)
                    <tr>
                        <td>{{ $card['name'] }}</td>
                        <td class="nowrap">{{ $card['inventory_number'] ?? '–' }}</td>
                        <td>{{ \App\Models\Office::find($card['office_id'])?->display_name }}</td>
                        <td>{{ $card['category']->name }}</td>
                        <td class="nowrap">{{ $card['commissioned_at'] ?? '–' }}</td>
                    </tr>
                @endforeach
            </table></div>
        @endif
        @if($report['no_office'])
            <p class="muted small">
                Завести нельзя: подразделение 1С не сопоставлено с филиалом – его нужно задать ниже
                (филиал у единицы обязателен).
            </p>
            <div class="table-wrap"><table>
                <tr><th>Наименование по учету</th><th>Инвентарный</th><th>Подразделение 1С</th></tr>
                @foreach($report['no_office'] as $card)
                    <tr>
                        <td>{{ $card['name'] }}</td>
                        <td class="nowrap">{{ $card['inventory_number'] ?? '–' }}</td>
                        <td class="nowrap">{{ $card['department_ref'] ?? 'в карточке не заполнено' }}</td>
                    </tr>
                @endforeach
            </table></div>
        @endif
    @endif

    <h2>Есть только в plan</h2>
    @if($report['no_ref']->isEmpty() && $report['missing']->isEmpty())
        <p class="muted small">Таких нет.</p>
    @else
        @if($report['no_ref']->isNotEmpty())
            <p class="muted small">
                Без ключа ОС: в 1С такую строку акта передачи не отправить – она уйдет только с
                ключом ОС или инвентарным номером.
            </p>
            @include('onec.assets', ['assets' => $report['no_ref']])
        @endif
        @if($report['missing']->isNotEmpty())
            <p class="muted small">
                Ключ ОС есть, но в ответе 1С такой карточки нет: в 1С ее списали и удалили, или
                ключ устарел. Сами ничего не удаляем – разбирают руками.
            </p>
            @include('onec.assets', ['assets' => $report['missing']])
        @endif
    @endif

    <h2>Расхождения по учетным полям</h2>
    @if($report['accounting'] === [])
        <p class="muted small">Расхождений нет.</p>
    @else
        <p class="muted small">Здесь 1С главнее: эти значения <code>onec:sync</code> перепишет по 1С.</p>
        <div class="table-wrap"><table>
            <tr><th>Единица</th><th>Поле</th><th>В plan</th><th>В 1С</th></tr>
            @foreach($report['accounting'] as $line)
                @foreach($line['diff'] as $field => [$was, $now])
                    <tr>
                        <td>@if($loop->first)<a href="{{ route('assets.show', $line['asset']) }}">{{ $line['asset']->title }}</a>@endif</td>
                        <td>{{ $fields[$field] ?? $field }}</td>
                        <td>{{ $was ?: '–' }}</td>
                        <td>{{ $now ?: '–' }}</td>
                    </tr>
                @endforeach
            @endforeach
        </table></div>
    @endif

    <h2>Расхождения по месту и МОЛ</h2>
    @if($report['placement'] === [])
        <p class="muted small">Расхождений нет.</p>
    @else
        <p class="muted small">
            Это plan главнее, и синк их не трогает: филиал и МОЛ меняет только передача
            (акт через <code>AssetMover</code>), а не чтение из 1С.
        </p>
        <div class="table-wrap"><table>
            <tr><th>Единица</th><th>Что</th><th>В plan</th><th>В 1С</th></tr>
            @foreach($report['placement'] as $line)
                @foreach($line['diff'] as $what => [$was, $now])
                    <tr>
                        <td>@if($loop->first)<a href="{{ route('assets.show', $line['asset']) }}">{{ $line['asset']->title }}</a>@endif</td>
                        <td>{{ $what }}</td>
                        <td>{{ $was ?: '–' }}</td>
                        <td>{{ $now ?: '–' }}</td>
                    </tr>
                @endforeach
            @endforeach
        </table></div>
    @endif

    @if($report['written_off'] || $report['conflicts'])
        <h2>Требует внимания</h2>
        @if($report['written_off'])
            <p class="muted small">Списано в 1С – <code>onec:sync</code> поставит дату списания и статус "Списано".</p>
            <div class="table-wrap"><table>
                <tr><th>Единица</th><th>Дата списания в 1С</th></tr>
                @foreach($report['written_off'] as $line)
                    <tr>
                        <td><a href="{{ route('assets.show', $line['asset']) }}">{{ $line['asset']->title }}</a></td>
                        <td class="nowrap">{{ $line['date'] }}</td>
                    </tr>
                @endforeach
            </table></div>
        @endif
        @if($report['conflicts'])
            <p class="muted small">
                Инвентарный номер из 1С занят другой единицей – не переписываем ни ту, ни эту:
                номер уникален, и чья это карточка, видно только человеку.
            </p>
            <div class="table-wrap"><table>
                <tr><th>Единица</th><th>Инвентарный из 1С</th><th>Номер уже у</th></tr>
                @foreach($report['conflicts'] as $line)
                    <tr>
                        <td><a href="{{ route('assets.show', $line['asset']) }}">{{ $line['asset']->title }}</a></td>
                        <td class="nowrap">{{ $line['inventory_number'] }}</td>
                        <td><a href="{{ route('assets.show', $line['other']) }}">{{ $line['other']->title }}</a></td>
                    </tr>
                @endforeach
            </table></div>
        @endif
    @endif
@endif

<h2>Подразделения 1С у филиалов</h2>
<p class="muted small">
    Ключ подразделения 1С (<code>Ref_Key</code>, GUID): по нему основное средство попадает в филиал.
    Филиалы приходят из HR, а это их единственное поле, которое заполняют здесь.
</p>
<form method="post" action="{{ route('onec.offices.update') }}" class="panel">
    @csrf @method('put')
    <div class="table-wrap"><table>
        <tr><th>Филиал</th><th>Город</th><th>Подразделение в 1С</th></tr>
        @foreach($offices as $office)
            <tr>
                <td>{{ $office->display_name }}</td>
                <td>{{ $office->city ?? '–' }}</td>
                <td>
                    <input name="departments[{{ $office->id }}]" value="{{ old('departments.'.$office->id, $office->onec_department_ref) }}"
                           placeholder="00000000-0000-0000-0000-000000000000" @disabled(! $canAssets)>
                </td>
            </tr>
        @endforeach
    </table></div>
    @if($canAssets)
        <div class="actions"><button class="btn primary">Сохранить</button></div>
    @endif
</form>
@endsection
