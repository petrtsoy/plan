<?php

namespace App\Models\Concerns;

use App\Services\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Пишет в журнал создание, изменение и удаление записи с разницей полей.
 * Модель задает $auditType – короткое имя сущности в журнале.
 */
trait Auditable
{
    // Служебные поля – в журнале только шум.
    private const AUDIT_SKIP = ['id', 'created_at', 'updated_at', 'remember_token', 'password', 'deleted_at', 'qr_code'];

    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            Audit::log($model->auditType, $model->getKey(), 'created', self::auditFields($model->getAttributes()));
        });

        static::updated(function (Model $model) {
            $changes = [];
            foreach (self::auditFields($model->getChanges()) as $field => $new) {
                $changes[$field] = [$model->getRawOriginal($field), $new];
            }
            if ($changes) {
                Audit::log($model->auditType, $model->getKey(), 'updated', $changes);
            }
        });

        static::deleted(function (Model $model) {
            Audit::log($model->auditType, $model->getKey(), 'deleted', self::auditFields($model->getRawOriginal()));
        });

        // Корзина (SoftDeletes): возврат из нее тоже пишем.
        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model) {
                Audit::log($model->auditType, $model->getKey(), 'restored');
            });
        }
    }

    private static function auditFields(array $attributes): array
    {
        return array_diff_key($attributes, array_flip(self::AUDIT_SKIP));
    }
}
