<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Одна настройка системы. Каталог и приведение типов – App\Services\Settings. */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];
}
