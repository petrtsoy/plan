{{-- Список единиц plan для страницы сверки: без 1С о них сказать нечего, кроме места. --}}
<div class="table-wrap"><table>
    <tr><th>Единица</th><th>Инвентарный</th><th>Филиал</th><th>Где стоит</th><th>Статус</th></tr>
    @foreach($assets as $asset)
        <tr>
            <td><a href="{{ route('assets.show', $asset) }}">{{ $asset->title }}</a></td>
            <td class="nowrap">{{ $asset->inventory_number ?? '–' }}</td>
            <td>{{ $asset->office?->display_name }}</td>
            <td>{{ $asset->room?->number ? 'каб. '.$asset->room->number : ($asset->isPlaced() ? 'на плане' : 'не расставлено') }}</td>
            <td class="nowrap">{{ $asset->status->label() }}</td>
        </tr>
    @endforeach
</table></div>
