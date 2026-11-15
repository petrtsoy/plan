<?php

namespace App\Http\Controllers;

use App\Models\AssetTransfer;
use App\Services\Transfers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Акты передачи: черновики с кнопкой "Оформить", оформленные со состоянием отправки
 * и печать акта. Строки в черновик кладет AssetMover, здесь их только оформляют.
 */
class TransferController extends Controller
{
    public function index(Transfers $transfers): View
    {
        return view('transfers.index', [
            'drafts' => $transfers->drafts(),
            'issued' => $transfers->issued(),
        ]);
    }

    public function issue(Request $request, AssetTransfer $transfer, Transfers $transfers): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:191']]);
        $transfers->issue($transfer, $data['reason'] ?? null);

        return redirect()->route('transfers.index')->with('ok', "Акт {$transfer->number} оформлен, документ в очереди в 1С.");
    }

    /** Печать: отдельная страница без меню, по Ctrl+P ложится на лист (@media print). */
    public function print(AssetTransfer $transfer): View
    {
        $transfer->load(['fromOffice', 'toOffice', 'fromMol', 'toMol', 'user', 'items.asset']);

        return view('transfers.print', ['transfer' => $transfer]);
    }
}
