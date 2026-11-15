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
 * Смена филиала или МОЛ – передача: строка в черновике акта с тем же направлением
 * (Transfers::attach); в 1С акт уходит при оформлении (Transfers::issue). В 1С пишет только
 * эта система, в том числе за перемещения ИТ-техники, сделанные в it.his.kz (origin = it).
 * ИТ-техника, перемещенная здесь, сразу уходит в it.his.kz – там ее место первично;
 * пришедшее из it обратно не отправляется. Перестановка внутри помещения (только точка
 * на плане) в историю не пишется.
 */
class AssetMover
{
    /** Акт, в который легла последняя передача (для moveMany). */
    private ?AssetTransfer $lastTransfer = null;

    public function __construct(private Transfers $transfers) {}

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
        $building = null;
        if ($to['room_id']) {
            $room = Room::with('floor.building')->findOrFail($to['room_id']);
            $to['floor_id'] = $room->floor_id;
            $building = $room->floor->building;
        } elseif ($to['floor_id']) {
            $building = Building::find(Floor::whereKey($to['floor_id'])->value('building_id'));
        }
        // Здание-кандидат проекта еще не наше: туда – только в вариант расстановки проекта.
        if ($building?->is_candidate) {
            throw ValidationException::withMessages(['room_id' => 'Это здание проекта, а не настоящее: расставлять его можно только в варианте проекта.']);
        }
        if ($building) {
            $officeId = $building->office_id;
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
        // != а не !==: ключи в $to идут в том порядке, в каком их передали.
        $relocated = array_map('intval', array_intersect_key($from, array_flip($keys))) != array_map('intval', array_intersect_key($to, array_flip($keys)));

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

            $this->lastTransfer = null;
            $handover = (int) $from['office_id'] !== (int) $to['office_id'] || (int) $from['mol_id'] !== (int) $to['mol_id'];
            if ($handover && $from['office_id']) {
                $this->lastTransfer = $this->transfers->attach($asset, $move);
            }

            // Место ИТ-техники первично в it.his.kz – туда сразу, не дожидаясь оформления акта.
            if ($asset->category->is_it && $move->origin !== 'it') {
                $move->outbox()->create(['target' => 'it', 'action' => 'move', 'payload' => [
                    'it_device_id' => $asset->it_device_id,
                    'inventory_number' => $asset->inventory_number,
                    'to_office_id' => $move->to_office_id,
                    'to_room_id' => $move->to_room_id,
                    'to_employee_id' => $move->to_employee_id,
                    'transfer_number' => $this->lastTransfer?->number,
                    'comment' => $comment,
                ]]);
            }

            return $move;
        });
    }

    /**
     * Несколько единиц в одно место разом (выделили на плане и перетащили, или список
     * "передать в филиал"). Все в одной транзакции; передачи ложатся в черновики актов
     * по направлениям – обычно один акт, с разных МОЛ – по акту на МОЛ.
     *
     * @param  iterable<Asset>  $assets
     * @return list<AssetTransfer> затронутые черновики актов
     */
    public function moveMany(iterable $assets, array $to, ?string $comment = null): array
    {
        return DB::transaction(function () use ($assets, $to, $comment) {
            $transfers = [];
            foreach ($assets as $asset) {
                $move = $this->move($asset, $to, $comment);
                if ($move && ($t = $this->lastTransfer)) {
                    $transfers[$t->id] = $t;
                }
            }

            return array_values($transfers);
        });
    }
}
