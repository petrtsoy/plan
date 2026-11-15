{{-- Ежедневная сводка сигналов. Письмо простым HTML: почтовые клиенты верстку из app.css не видят. --}}
@php
    $th = 'text-align:left;padding:6px 10px;font-weight:500;color:#64748b;border-bottom:1px solid #e2e8f0;';
    $td = 'padding:6px 10px;border-bottom:1px solid #f1f5f9;vertical-align:top;';
    $place = function ($asset) {
        return collect([$asset->office?->display_name, $asset->floor?->path, $asset->room?->title])
            ->filter()->implode(' / ') ?: 'место не указано';
    };
@endphp
<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#0f172a;line-height:1.5;">
    <h2 style="margin:0 0 4px;font-size:18px;">{{ config('app.name') }}: ТО, поверка и сроки</h2>
    <p style="margin:0 0 20px;color:#64748b;">{{ now()->format('d.m.Y') }}</p>

    @if(count($overdue))
        <h3 style="font-size:15px;margin:0 0 8px;color:#b91c1c;">Просрочено – {{ count($overdue) }}</h3>
        <table style="border-collapse:collapse;width:100%;margin-bottom:24px;">
            <tr><th style="{{ $th }}">Срок был</th><th style="{{ $th }}">Что</th><th style="{{ $th }}">Единица</th><th style="{{ $th }}">Место</th><th style="{{ $th }}">Организация</th></tr>
            @foreach($overdue as $plan)
                <tr>
                    <td style="{{ $td }}white-space:nowrap;color:#b91c1c;">{{ $plan->next_due_at->format('d.m.Y') }}</td>
                    <td style="{{ $td }}">{{ $plan->kind->label() }}</td>
                    <td style="{{ $td }}">{{ $plan->asset->title }}{{ $plan->asset->inventory_number ? ' ('.$plan->asset->inventory_number.')' : '' }}</td>
                    <td style="{{ $td }}">{{ $place($plan->asset) }}</td>
                    <td style="{{ $td }}">{{ $plan->contractor?->name ?? '–' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if(count($soon))
        <h3 style="font-size:15px;margin:0 0 8px;color:#b45309;">Срок в ближайшие {{ $days }} дней – {{ count($soon) }}</h3>
        <table style="border-collapse:collapse;width:100%;margin-bottom:24px;">
            <tr><th style="{{ $th }}">Срок</th><th style="{{ $th }}">Что</th><th style="{{ $th }}">Единица</th><th style="{{ $th }}">Место</th><th style="{{ $th }}">Организация</th></tr>
            @foreach($soon as $plan)
                <tr>
                    <td style="{{ $td }}white-space:nowrap;">{{ $plan->next_due_at->format('d.m.Y') }}</td>
                    <td style="{{ $td }}">{{ $plan->kind->label() }}</td>
                    <td style="{{ $td }}">{{ $plan->asset->title }}{{ $plan->asset->inventory_number ? ' ('.$plan->asset->inventory_number.')' : '' }}</td>
                    <td style="{{ $td }}">{{ $place($plan->asset) }}</td>
                    <td style="{{ $td }}">{{ $plan->contractor?->name ?? '–' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if(count($warranty))
        <h3 style="font-size:15px;margin:0 0 8px;">Гарантия кончается – {{ count($warranty) }}</h3>
        <table style="border-collapse:collapse;width:100%;margin-bottom:24px;">
            <tr><th style="{{ $th }}">Гарантия до</th><th style="{{ $th }}">Единица</th><th style="{{ $th }}">Место</th></tr>
            @foreach($warranty as $asset)
                <tr>
                    <td style="{{ $td }}white-space:nowrap;">{{ $asset->warranty_until->format('d.m.Y') }}</td>
                    <td style="{{ $td }}">{{ $asset->title }}{{ $asset->inventory_number ? ' ('.$asset->inventory_number.')' : '' }}</td>
                    <td style="{{ $td }}">{{ $place($asset) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if(count($contracts))
        <h3 style="font-size:15px;margin:0 0 8px;">Договоры сервисных организаций – {{ count($contracts) }}</h3>
        <table style="border-collapse:collapse;width:100%;margin-bottom:24px;">
            <tr><th style="{{ $th }}">Договор до</th><th style="{{ $th }}">Организация</th><th style="{{ $th }}">Договор</th></tr>
            @foreach($contracts as $contractor)
                <tr>
                    <td style="{{ $td }}white-space:nowrap;{{ $contractor->contract_until->lt(today()) ? 'color:#b91c1c;' : '' }}">{{ $contractor->contract_until->format('d.m.Y') }}</td>
                    <td style="{{ $td }}">{{ $contractor->name }}</td>
                    <td style="{{ $td }}">{{ $contractor->contract_number ?? '–' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <p style="margin:0;color:#64748b;">
        Полный список – <a href="{{ route('maintenance.index') }}" style="color:#0d9488;">{{ route('maintenance.index') }}</a>
    </p>
</div>
