<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetMove;
use App\Models\AssetTransfer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Акты передачи. Шапка акта – одно направление, как у документа перемещения ОС в 1С:
 * филиал и МОЛ откуда, филиал и МОЛ куда. Каждая передача (AssetMover) ложится строкой
 * в открытый черновик с тем же направлением – так несколько единиц, перемещенных разом
 * или по одной в течение дня, оказываются в одном акте. Единицы с разных МОЛ или в
 * разные филиалы – разные акты: в 1С это тоже разные документы.
 * Оформление (issue) закрывает акт и ставит его в очередь в 1С.
 */
class Transfers
{
    public function attach(Asset $asset, AssetMove $move): AssetTransfer
    {
        $direction = [
            'from_office_id' => $move->from_office_id,
            'to_office_id' => $move->to_office_id,
            'from_mol_id' => $move->from_mol_id,
            'to_mol_id' => $move->to_mol_id,
            'origin' => $move->origin,
        ];

        // Черновики – свои у каждого пользователя: чужой акт не должен расти без ведома автора.
        $transfer = AssetTransfer::where('status', AssetTransfer::DRAFT)
            ->where('user_id', $move->user_id)
            ->where(fn ($q) => $this->whereDirection($q, $direction))
            ->lockForUpdate()
            ->first();

        $transfer ??= AssetTransfer::create($direction + [
            'number' => $this->nextNumber(),
            'transfer_date' => today(),
            'reason' => $move->comment,
            'user_id' => $move->user_id,
        ]);

        // Та же единица уже в черновике (переставили еще раз) – строка указывает на последнее перемещение.
        $transfer->items()->updateOrCreate(['asset_id' => $asset->id], [
            'move_id' => $move->id,
            'inventory_number' => $asset->inventory_number,
            'accounting_ref' => $asset->accounting_ref,
            'title' => $asset->title,
        ]);

        return $transfer;
    }

    /** Оформить: акт закрыт для строк и уходит в 1С одним документом со всеми строками. */
    public function issue(AssetTransfer $transfer, ?string $reason = null): AssetTransfer
    {
        if (! $transfer->isDraft()) {
            throw ValidationException::withMessages(['transfer' => "Акт {$transfer->number} уже оформлен."]);
        }

        return DB::transaction(function () use ($transfer, $reason) {
            $transfer->update([
                'status' => AssetTransfer::ISSUED,
                'issued_at' => now(),
                'transfer_date' => today(),
                'reason' => $reason ?? $transfer->reason,
            ]);

            // В 1С – только то, что там учитывается как основное средство (есть ключ или инвентарный номер).
            $lines = $transfer->items()->where(fn ($q) => $q->whereNotNull('accounting_ref')->orWhereNotNull('inventory_number'))
                ->get(['asset_id', 'inventory_number', 'accounting_ref', 'title'])->toArray();
            if ($lines) {
                $transfer->outbox()->create(['target' => 'onec', 'action' => 'transfer', 'payload' => [
                    'number' => $transfer->number,
                    'date' => $transfer->transfer_date->toDateString(),
                    'from_office_id' => $transfer->from_office_id,
                    'to_office_id' => $transfer->to_office_id,
                    'from_mol_id' => $transfer->from_mol_id,
                    'to_mol_id' => $transfer->to_mol_id,
                    'reason' => $transfer->reason,
                    'lines' => $lines,
                ]]);
            }

            return $transfer;
        });
    }

    /**
     * Открытые черновики для страницы актов – с кнопкой "Оформить": свои и те,
     * что пришли из it.his.kz. Чужой акт не должен расти без ведома автора, поэтому
     * черновики свои у каждого; у перемещений из it.his.kz автора здесь нет –
     * их оформляет вручную тот, кто смотрит страницу (решение этапа 4).
     */
    public function drafts(?int $userId = null)
    {
        return AssetTransfer::where('status', AssetTransfer::DRAFT)
            ->where(fn ($q) => $q->where('user_id', $userId ?? Auth::id())->orWhere('origin', 'it'))
            ->with(['fromOffice', 'toOffice', 'fromMol', 'toMol', 'items.asset'])
            ->latest('id')->get();
    }

    /** Оформленные акты – с состоянием отправки из outbox. */
    public function issued(int $perPage = 25): LengthAwarePaginator
    {
        return AssetTransfer::where('status', AssetTransfer::ISSUED)
            ->with(['fromOffice', 'toOffice', 'fromMol', 'toMol', 'user', 'outbox'])
            ->withCount('items')
            ->latest('issued_at')->latest('id')
            ->paginate($perPage);
    }

    private function whereDirection($query, array $direction): void
    {
        foreach ($direction as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }
    }

    /** "П-2026-00042": сквозной номер в пределах года. */
    private function nextNumber(): string
    {
        $prefix = 'П-'.today()->year.'-';
        $last = AssetTransfer::where('number', 'like', $prefix.'%')->lockForUpdate()->max('number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 5, '0', STR_PAD_LEFT);
    }
}
