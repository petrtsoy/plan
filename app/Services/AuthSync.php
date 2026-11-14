<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Привязка пользователя к системе в auth.his.kz (POST /api/sync/user).
 *
 * auth пускает в систему только тех, у кого есть привязка к ее system_code,
 * поэтому каждого заведенного здесь пользователя толкаем туда сами – так же
 * делают hr и supply. Отключенному (is_active = false) привязку снимаем.
 * Сбой не мешает сохранению: пишем в лог и возвращаем false, а повторить
 * можно командой plan:auth-sync.
 */
class AuthSync
{
    public function push(User $user): bool
    {
        $cfg = config('services.sso');
        if (! $cfg['enabled'] || ! $cfg['client_id'] || ! $cfg['client_secret']) {
            return false;
        }

        try {
            $response = Http::timeout(10)->acceptJson()->post($cfg['auth_url'].'/api/sync/user', [
                'client_id' => $cfg['client_id'],
                'client_secret' => $cfg['client_secret'],
                'iin' => $user->iin,
                'local_user_id' => $user->id,
                'local_username' => $user->iin,
                'full_name' => $user->name,
                'email' => $user->email,
                'active' => $user->is_active,
            ]);
        } catch (\Throwable $e) {
            Log::warning('auth sync: нет связи', ['user' => $user->id, 'error' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful() || ! $response->json('success')) {
            Log::warning('auth sync: отказ', ['user' => $user->id, 'status' => $response->status(), 'error' => $response->json('error')]);

            return false;
        }

        return true;
    }
}
