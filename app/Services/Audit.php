<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

/** Единая точка записи в журнал изменений. */
class Audit
{
    public static function log(string $subjectType, int $subjectId, string $action, array $changes = [], ?string $comment = null, ?int $userId = null): void
    {
        $request = app()->runningInConsole() ? null : request();

        AuditLog::create([
            'user_id' => $userId ?? Auth::id(),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => $action,
            'changes' => $changes ? self::scalarize($changes) : null,
            'comment' => $comment,
            'ip' => $request?->ip(),
        ]);
    }

    /** Вход, отказ во входе, выход. */
    public static function auth(string $action, ?int $userId, ?string $comment = null, array $meta = []): void
    {
        self::log('auth', $userId ?? 0, $action, $meta, $comment, $userId);
    }

    /** Енамы и даты – в строки, чтобы журнал читался одинаково при любом источнике. */
    private static function scalarize(array $values): array
    {
        return array_map(function ($v) {
            return match (true) {
                is_array($v) => self::scalarize($v),
                $v instanceof \BackedEnum => $v->value,
                $v instanceof \DateTimeInterface => $v->format('Y-m-d H:i:s'),
                default => $v,
            };
        }, $values);
    }
}
