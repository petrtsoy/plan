<?php

namespace App\Models;

use App\Enums\Area;
use App\Enums\Role;
use App\Models\Concerns\Auditable;
use App\Services\AuthSync;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Пользователь системы. Заводит администратор (выбором сотрудника из HR),
 * входят только через SSO по ИИН. Без записи здесь вход закрыт.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, Notifiable;

    public string $auditType = 'user';

    protected $fillable = ['name', 'iin', 'email', 'role', 'is_active'];

    protected $hidden = ['remember_token'];

    protected static function booted(): void
    {
        // Привязку в auth.his.kz держим в согласии с записью здесь; вход (last_login_at) не в счет.
        static::created(fn (User $user) => app(AuthSync::class)->push($user));
        static::updated(function (User $user) {
            if ($user->wasChanged(['iin', 'name', 'email', 'is_active'])) {
                app(AuthSync::class)->push($user);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function edits(Area $area): bool
    {
        return $this->is_active && $this->role->edits($area);
    }

    /** Правит хоть что-то из перечисленного. */
    public function editsAny(Area ...$areas): bool
    {
        foreach ($areas as $area) {
            if ($this->edits($area)) {
                return true;
            }
        }

        return false;
    }

    public function isAdmin(): bool
    {
        return $this->is_active && $this->role === Role::Admin;
    }
}
