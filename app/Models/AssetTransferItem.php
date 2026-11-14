<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Строка акта: единица учета с инвентарным номером и названием на момент передачи. */
class AssetTransferItem extends Model
{
    protected $guarded = [];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(AssetTransfer::class, 'transfer_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class)->withTrashed();
    }

    public function move(): BelongsTo
    {
        return $this->belongsTo(AssetMove::class, 'move_id');
    }
}
