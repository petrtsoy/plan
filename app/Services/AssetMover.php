<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetMove;
use App\Models\AssetTransfer;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Перемещение и передача единицы учета: новое место и закрепление + запись в историю.
 *
 * Смена филиала или МОЛ – передача: создается акт (asset_transfers) и в той же
 * транзакции ставится отправка в 1С (документ перемещения ОС). В 1С пишет только эта
 * система, в том числе за перемещения ИТ-техники, сделанные в it.his.kz (origin = it).
 * ИТ-техника, перемещенная здесь, уходит еще и в it.his.kz – там ее место первично;
 * пришедшее из it обратно не отправляется. Перестановка внутри помещения (только точка
 * на плане) в историю не пишется.
 */
class AssetMover
{
    /**
     * @param  array  $to  office_id, room_id, floor_id, x, y, rotation, mol_id, employee_id – что не передано, остается
     * @param  ?int  $itMoveId  перемещение пришло из it.his.kz: его id (повтор события – ничего не делаем)
     */
    public function move(Asset $asset, array $to, ?string $comment = null, ?int $layoutId = null, ?int $itMoveId = null): ?AssetMove
    {
        if ($itMoveId && AssetMove::where('it_move_id', $itMoveId)->exists()) {
            return null;
        }

        $given = $to;
        $to = array_intersect_key($to, array_flip(Asset::PLACEMENT)) + $asset->only(Asset::PLACEMENT);

        // Помещение задает этаж и филиал; этаж без помещения (коридор) – филиал по зданию.
        if ($to['room_id']) {
            $room = Room::with('floor.building')->findOrFail($to['room_id']);
            $to['floor_id'] = $room->floor_id;
            $officeId = $room->floor->building->office_id;
        } elseif ($to['floor_id']) {
            $officeId = Building::whereKey(Floor::whereKey($to['floor_id'])->value('building_id'))->value('office_id');
        }
        if (isset($officeId)) {
            if (! empty($given['office_id']) && (int) $given['office_id'] !== (int) $officeId) {
                throw ValidationException::withMessages(['room_id' => 'Помещение не относится к выбранному филиалу.']);
            }
            $to['office_id'] = $officeId;
        }
        if (! $to['floor_id']) {
            $to['x'] = $to['y'] = null;
        }

        $from = $asset->only(Asset::PLACEMENT);
        $keys = ['office_id', 'room_id', 'mol_id', 'employee_id'];
        $relocated = array_map('intval', array_intersect_key($from, array_flip($keys))) !== array_map('intval', array_intersect_key($to, array_flip($keys)));

        return DB::transaction(function () use ($asset, $from, $to, $relocated, $comment, $layoutId, $itMoveId) {
            $asset->update($to);
            if (! $relocated) {
                return null;
            }

            $move = AssetMove::create([
                'asset_id' => $asset->id,
                'from_office_id' => $from['office_id'], 'to_office_id' => $to['office_id'],
                'from_room_id' => $from['room_id'], 'to_room_id' => $to['room_id'],
                'from_mol_id' => $from['mol_id'], 'to_mol_id' => $to['mol_id'],
                'from_employee_id' => $from['employee_id'], 'to_employee_id' => $to['employee_id'],
                'layout_id' => $layoutId,
                'origin' => $itMoveId ? 'it' : 'plan',
                'it_move_id' => $itMoveId,
                'user_id' => Auth::id(),
                'comment' => $comment,
                'moved_at' => now(),
            ]);

            $handover = (int) $from['office_id'] !== (int) $to['office_id'] || (int) $from['mol_id'] !== (int) $to['mol_id'];
            if ($handover && $from['office_id']) {
                $this->transfer($asset, $move, $comment);
            }

            return $move;
        });
    }

    private function transfer(Asset $asset, AssetMove $move, ?string $reason): AssetTransfer
    {
        $transfer = AssetTransfer::create([
            'number' => $this->nextNumber(),
            'transfer_date' => today(),
            'asset_id' => $asset->id,
            'move_id' => $move->id,
            'from_office_id' => $move->from_office_id,
            'to_office_id' => $move->to_office_id,
            'from_mol_id' => $move->from_mol_id,
            'to_mol_id' => $move->to_mol_id,
            'reason' => $reason,
            'user_id' => Auth::id(),
        ]);

        $payload = [
            'number' => $transfer->number,
            'date' => $transfer->transfer_date->toDateString(),
            'inventory_number' => $asset->inventory_number,
            'accounting_ref' => $asset->accounting_ref,
            'from_office_id' => $move->from_office_id,
            'to_office_id' => $move->to_office_id,
            'from_mol_id' => $move->from_mol_id,
            'to_mol_id' => $move->to_mol_id,
            'reason' => $reason,
        ];

        // В 1С – только то, что там учитывается как основное средство (есть ключ или инвентарный номер).
        if ($asset->accounting_ref || $asset->inventory_number) {
            $transfer->outbox()->create(['target' => 'onec', 'action' => 'transfer', 'payload' => $payload]);
        }
        if ($asset->category->is_it && $move->origin !== 'it') {
            $transfer->outbox()->create(['target' => 'it', 'action' => 'transfer', 'payload' => $payload + [
                'it_device_id' => $asset->it_device_id,
                'to_employee_id' => $move->to_employee_id,
            ]]);
        }

        return $transfer;
    }

    /** "П-2026-00042": сквозной номер в пределах года. */
    private function nextNumber(): string
    {
        $prefix = 'П-'.today()->year.'-';
        $last = AssetTransfer::where('number', 'like', $prefix.'%')->lockForUpdate()->max('number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 5, '0', STR_PAD_LEFT);
    }
}
