<?php

namespace App\Http\Controllers;

use App\Enums\MaintenanceKind;
use App\Enums\MaintenanceResult;
use App\Models\Asset;
use App\Models\Contractor;
use App\Models\Layer;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceRecord;
use App\Models\Office;
use App\Services\Maintenance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ТО, поверка и прочие периодические работы: список "что пора обслуживать", регламент
 * единицы и журнал выполнения. Запись выполнения – только через Maintenance::record()
 * (она же сдвигает срок в регламенте).
 */
class MaintenanceController extends Controller
{
    public function __construct(private readonly Maintenance $maintenance) {}

    /** Что пора обслуживать: состояния и пороги – те же, что раскрашивают единицы на плане этажа. */
    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $days = (int) config('services.alerts.maintenance_days');

        $assets = $this->maintenance->dueList($filters, $days)
            ->with(['category.layer', 'office', 'room', 'floor.building', 'contractor',
                'maintenancePlans' => fn ($q) => $q->where('is_active', true)->orderBy('next_due_at'),
                'maintenancePlans.contractor'])
            // Сначала просроченные, в конце – у чего срока нет вовсе.
            ->orderByRaw('due_at is null, due_at')
            ->paginate(50)->withQueryString();

        return view('maintenance.index', [
            'assets' => $assets,
            'counts' => $this->maintenance->dueCounts($filters, $days),
            'filters' => $filters,
            'days' => $days,
        ] + $this->options());
    }

    /** Журнал выполненных работ. */
    public function records(Request $request): View
    {
        $filters = $this->filters($request);

        $records = MaintenanceRecord::query()
            ->with(['asset.office', 'asset.room', 'contractor', 'user', 'attachments'])
            ->when($filters['office_id'] ?? null, fn ($q, $id) => $q->whereHas('asset', fn ($a) => $a->where('office_id', $id)))
            ->when($filters['contractor_id'] ?? null, fn ($q, $id) => $q->where('contractor_id', $id))
            ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($filters['result'] ?? null, fn ($q, $result) => $q->where('result', $result))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('performed_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('performed_at', '<=', $to))
            ->latest('performed_at')->latest('id')
            ->paginate(50)->withQueryString();

        return view('maintenance.records', ['records' => $records, 'filters' => $filters] + $this->options());
    }

    /** Карточка обслуживания единицы: регламент, запись выполнения, что уже сделано. */
    public function asset(Asset $asset): View
    {
        $asset->load(['category.layer', 'model', 'office', 'room', 'floor.building', 'contractor',
            'maintenancePlans.contractor', 'maintenanceRecords.contractor', 'maintenanceRecords.user',
            'maintenanceRecords.attachments']);

        return view('maintenance.asset', [
            'asset' => $asset,
            'days' => (int) config('services.alerts.maintenance_days'),
        ] + $this->options());
    }

    /** Регламент по модели: интервалы из asset_models.intervals, уже заведенное не трогает. */
    public function planFromModel(Asset $asset): RedirectResponse
    {
        $n = $this->maintenance->planFromModel($asset);

        return back()->with($n ? 'ok' : 'error', $n
            ? "Добавлено по регламенту модели: {$n}."
            : 'Добавлять нечего: у модели нет интервалов или они уже заведены.');
    }

    public function storePlan(Request $request, Asset $asset): RedirectResponse
    {
        $data = $this->validatedPlan($request, $asset);

        // Вид работ у единицы один (unique asset_id + kind): повтор – правка существующего.
        MaintenancePlan::updateOrCreate(['asset_id' => $asset->id, 'kind' => $data['kind']], $data);

        return back()->with('ok', 'Регламент сохранен.');
    }

    public function updatePlan(Request $request, MaintenancePlan $plan): RedirectResponse
    {
        $plan->update($this->validatedPlan($request, $plan->asset, $plan));

        return back()->with('ok', 'Регламент сохранен.');
    }

    /** Выполнение: акт ТО или свидетельство о поверке. Срок сдвигает Maintenance::record(). */
    public function storeRecord(Request $request, Asset $asset): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(MaintenanceKind::class)],
            'performed_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'result' => ['required', Rule::enum(MaintenanceResult::class)],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after:performed_at'],
            'contractor_id' => ['nullable', 'exists:contractors,id'],
            'document_number' => ['nullable', 'string', 'max:64'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'document' => ['nullable', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx'],
        ], [], ['performed_at' => 'дата выполнения', 'valid_until' => 'срок действия']);

        $kind = MaintenanceKind::from($data['kind']);
        $record = $this->maintenance->record($asset, $kind, $data);

        if ($file = $request->file('document')) {
            $path = Storage::disk('local')->putFile('attachments/maintenance/'.$record->id, $file);
            $record->attachments()->create([
                'kind' => $kind === MaintenanceKind::Verification ? 'certificate' : 'act',
                'name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'user_id' => $request->user()->id,
            ]);
        }

        $next = $record->plan?->fresh()?->next_due_at;

        return back()->with('ok', $next ? 'Записано. Следующий срок: '.$next->format('d.m.Y').'.' : 'Записано.');
    }

    private function validatedPlan(Request $request, Asset $asset, ?MaintenancePlan $plan = null): array
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(MaintenanceKind::class),
                Rule::unique('maintenance_plans')->where('asset_id', $asset->id)->ignore($plan)],
            'interval_months' => ['required', 'integer', 'between:1,240'],
            'contractor_id' => ['nullable', 'exists:contractors,id'],
            'next_due_at' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], ['kind.unique' => 'Такой вид работ у этой единицы уже в регламенте – правьте его.']);
        // В форме правки галочка есть всегда, поэтому ее отсутствие – снятая галочка (как у пользователей).
        $data['is_active'] = $request->boolean('is_active', $plan === null);

        return $data;
    }

    /** Фильтры списков – одни и те же поля, пустые отбрасываем. */
    private function filters(Request $request): array
    {
        return array_filter(
            $request->only(['office_id', 'contractor_id', 'layer_id', 'kind', 'state', 'result', 'q', 'from', 'to']),
            fn ($v) => $v !== null && $v !== '',
        );
    }

    /** Справочники для фильтров и форм. */
    private function options(): array
    {
        return [
            'offices' => Office::where('is_active', true)->orderByDesc('is_branch')->orderBy('name')->get(),
            'contractors' => Contractor::orderBy('name')->get(),
            'layers' => Layer::orderBy('sort')->orderBy('name')->get(),
            'kinds' => MaintenanceKind::options(),
            'results' => MaintenanceResult::options(),
        ];
    }
}
