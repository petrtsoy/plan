<?php

namespace App\Http\Controllers;

use App\Models\Contractor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Сервисные организации: ТО медтехники, поверка, перезарядка огнетушителей – и сроки договоров. */
class ContractorController extends Controller
{
    public function index(): View
    {
        $contractors = Contractor::query()
            ->withCount(['maintenancePlans as plans_count' => fn ($q) => $q->where('is_active', true)])
            ->withCount('maintenanceRecords as records_count')
            ->orderBy('name')->get();

        return view('contractors.index', [
            'contractors' => $contractors,
            'warnDays' => (int) config('services.alerts.warranty_days'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Contractor::create($this->validated($request));

        return back()->with('ok', 'Организация добавлена.');
    }

    public function update(Request $request, Contractor $contractor): RedirectResponse
    {
        $contractor->update($this->validated($request));

        return back()->with('ok', 'Сохранено.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'bin' => ['nullable', 'digits:12'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:191'],
            'contact_person' => ['nullable', 'string', 'max:191'],
            'license' => ['nullable', 'string', 'max:191'],
            'contract_number' => ['nullable', 'string', 'max:64'],
            'contract_until' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [], ['bin' => 'БИН', 'contract_until' => 'договор до']);
    }
}
