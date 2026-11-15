<?php

namespace App\Http\Controllers;

use App\Models\Office;
use App\Services\Audit;
use App\Services\OnecSync;
use His\OnecOdata\OnecException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Сверка с 1С: что есть только в 1С, что только в plan, расхождения по учетным полям и по
 * месту с МОЛ. Страница ничего не правит – записывает только onec:sync, место и МОЛ не
 * перетирает и он. Здесь же сопоставление подразделений 1С с филиалами: без него основное
 * средство не разложить по филиалам, а автоматически сопоставить его нечем.
 */
class OnecController extends Controller
{
    /** Расхождения считаются на открытии страницы: 1С читается заново (обход – секунды). */
    public function index(OnecSync $sync): View
    {
        $report = null;
        $error = null;

        try {
            $report = $sync->compare();
        } catch (OnecException|RuntimeException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            report($e);
            $error = 'Сверка не удалась: '.$e->getMessage();
        }

        return view('onec.index', [
            'report' => $report,
            'error' => $error,
            'offices' => Office::where('is_active', true)->orderByDesc('is_branch')->orderBy('name')->get(),
            'fields' => self::FIELDS,
        ]);
    }

    /** Подразделение 1С у филиалов – единственное, что здесь правят руками. */
    public function updateOffice(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'departments' => ['array'],
            'departments.*' => ['nullable', 'string', 'max:64'],
        ]);

        $changes = [];
        foreach (Office::whereIn('id', array_keys($data['departments'] ?? []))->get() as $office) {
            $was = $office->onec_department_ref;
            $now = trim((string) $data['departments'][$office->id]) ?: null;
            if ($was !== $now) {
                $office->update(['onec_department_ref' => $now]);
                $changes[$office->display_name] = [$was, $now];
            }
        }

        if ($changes) {
            Audit::log('office', 0, 'updated', $changes, 'Подразделения 1С');
        }

        return back()->with('ok', $changes ? 'Сопоставление сохранено.' : 'Менять было нечего.');
    }

    /** Учетные поля единицы по-русски – для таблицы расхождений. */
    private const FIELDS = [
        'accounting_ref' => 'Ключ ОС',
        'accounting_name' => 'Наименование по учету',
        'inventory_number' => 'Инвентарный номер',
        'serial_number' => 'Заводской номер',
        'price' => 'Стоимость',
        'commissioned_at' => 'Принято к учету',
        'department' => 'Подразделение',
    ];
}
