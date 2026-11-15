<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Файл к записи (единица учета, запись ТО): в storage/app/private/attachments, отдается
 * только вошедшим. Убирают из карточки мягко (SoftDeletes) – файл на диске остается.
 */
class Attachment extends Model
{
    use Auditable, SoftDeletes;

    public string $auditType = 'attachment';

    protected $guarded = [];

    public const KINDS = [
        'photo' => 'Фото',
        'passport' => 'Паспорт или руководство',
        'registration' => 'Регистрационное удостоверение',
        'certificate' => 'Свидетельство о поверке',
        'act' => 'Акт',
        'other' => 'Другое',
    ];

    /** Показываем в браузере только безопасные типы; остальное (svg, html…) – скачиванием. */
    public const INLINE = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf'];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Кто загрузил. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isImage(): bool
    {
        return in_array($this->mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true);
    }

    public function url(): string
    {
        return '/files/'.$this->id;
    }
}
