<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Что нужно отправить в 1С или it.his.kz. Ставится в транзакции с изменением, отправляет outbox:send. */
class OutboxMessage extends Model
{
    public const TARGETS = ['onec' => '1С', 'it' => 'it.his.kz'];

    /** После стольких неудач – failed, повторяет только человек. */
    public const MAX_ATTEMPTS = 10;

    protected $table = 'outbox';

    protected $guarded = [];

    // Как default в БД: иначе у только что созданной записи их нет до refresh().
    protected $attributes = ['status' => 'pending', 'attempts' => 0];

    protected function casts(): array
    {
        return ['payload' => 'array', 'next_attempt_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()));
    }
}
