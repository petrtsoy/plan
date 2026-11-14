<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Вход через SSO auth.his.kz (OAuth 2.0 Authorization Code), порт из supply.
 * Гейт fail-closed: пускаем только тех, кого администратор завел в users
 * и не отключил.
 */
class SsoController extends Controller
{
    /** Метка нашего state: отличает ответ на наш запрос от входа с дашборда auth.his.kz. */
    private const STATE_PREFIX = 'rp.';

    public function redirect(Request $request): RedirectResponse
    {
        $cfg = config('services.sso');
        abort_unless($cfg['enabled'] && $cfg['client_id'], 404);

        $state = self::STATE_PREFIX.Str::random(40);
        $verifier = Str::random(64);
        $request->session()->put(['sso_state' => $state, 'sso_verifier' => $verifier]);

        // PKCE: auth.his.kz пока игнорирует, но вреда нет.
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return redirect()->away($cfg['auth_url'].'/oauth/authorize?'.http_build_query([
            'client_id' => $cfg['client_id'],
            'redirect_uri' => $cfg['redirect_uri'],
            'response_type' => 'code',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        $cfg = config('services.sso');
        abort_unless($cfg['enabled'] && $cfg['client_id'], 404);

        $expected = $request->session()->pull('sso_state');
        $verifier = $request->session()->pull('sso_verifier');
        $given = is_string($request->query('state')) ? $request->query('state') : '';

        if (! $expected) {
            // Вход с дашборда auth.his.kz: код не наш, принимать нельзя (login-CSRF),
            // начинаем собственный поток – повторно ЭЦП там не спросят.
            if (! str_starts_with($given, self::STATE_PREFIX)) {
                return redirect()->route('sso.redirect');
            }

            return redirect()->route('login')
                ->with('error', 'Сессия входа не сохранилась. Проверьте, что в браузере разрешены cookie, и войдите заново.');
        }

        if (! hash_equals($expected, $given)) {
            return redirect()->route('login')->with('error', 'Не удалось проверить state. Войдите заново.');
        }

        $code = $request->query('code');
        if (! $code) {
            return redirect()->route('login')->with('error', 'Сервер авторизации не вернул код.');
        }

        $accessToken = Http::asForm()->timeout(10)->post($cfg['auth_url'].'/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri' => $cfg['redirect_uri'],
            'code' => $code,
            'code_verifier' => $verifier,
        ])->json('access_token');

        if (! $accessToken) {
            return redirect()->route('login')->with('error', 'Сервер авторизации отклонил обмен кода на токен.');
        }

        $info = Http::withToken($accessToken)->timeout(10)->get($cfg['auth_url'].'/oauth/userinfo')->json() ?? [];
        $iin = (string) ($info['iin'] ?? '');

        if ($iin === '') {
            Audit::auth('login_denied', null, 'Сервер авторизации не вернул ИИН');

            return redirect()->route('login')->with('error', 'Сервер авторизации не вернул ИИН.');
        }

        $user = User::where('iin', $iin)->where('is_active', true)->first();
        if (! $user) {
            Audit::auth('login_denied', null, 'Нет активной учетной записи', ['iin' => $iin]);

            return redirect()->route('login')->with('error', 'Доступа к '.config('app.name').' нет. Обратитесь к администратору системы.');
        }

        $this->login($request, $user);
        $this->registerMapping($cfg, $info, $user);

        return redirect()->intended(route('dashboard'));
    }

    public static function login(Request $request, User $user): void
    {
        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        Audit::auth('login', $user->id);
    }

    /** Связка auth_user_id <-> локальный пользователь: без нее не придет обратный выход. */
    private function registerMapping(array $cfg, array $info, User $user): void
    {
        if (empty($info['sub'])) {
            return;
        }

        try {
            Http::timeout(5)->post($cfg['auth_url'].'/api/mappings', [
                'client_id' => $cfg['client_id'],
                'client_secret' => $cfg['client_secret'],
                'auth_user_id' => $info['sub'],
                'system_code' => $cfg['system_code'],
                'local_user_id' => $user->id,
                'local_username' => $user->iin,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SSO: связка не зарегистрирована', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Обратный выход: auth.his.kz шлет POST при выходе из любой системы,
     * подпись HMAC-SHA256 на секрете клиента.
     */
    public function backChannelLogout(Request $request): Response
    {
        $cfg = config('services.sso');
        if (! $cfg['enabled'] || ! $cfg['client_secret']) {
            return response('sso disabled', 404);
        }

        $input = $request->only(['action', 'user_id', 'local_user_id', 'timestamp', 'signature']);

        if (($input['action'] ?? null) !== 'logout' || empty($input['user_id'])) {
            return response('invalid request', 400);
        }

        if (abs(now()->timestamp - (int) ($input['timestamp'] ?? 0)) > 300) {
            return response('stale request', 400);
        }

        if (! $this->signatureValid($input, (string) $cfg['client_secret'])) {
            Log::warning('SSO logout: подпись не сошлась', ['user_id' => $input['user_id']]);

            return response('bad signature', 403);
        }

        if ($localUserId = $input['local_user_id'] ?? null) {
            DB::table('sessions')->where('user_id', $localUserId)->delete();
            Audit::auth('logout', (int) $localUserId, 'Выход с сервера авторизации');
        }

        return response('ok', 200);
    }

    /**
     * Сервер подписывает json_encode() с натуральными типами, а до нас значения
     * доезжают строками – перебираем правдоподобные варианты (как в supply).
     */
    private function signatureValid(array $input, string $secret): bool
    {
        $received = (string) ($input['signature'] ?? '');
        if ($received === '') {
            return false;
        }

        $localRaw = $input['local_user_id'] ?? null;
        $localVariants = [$localRaw];
        if ($localRaw !== null && $localRaw !== '') {
            $localVariants[] = (int) $localRaw;
            $localVariants[] = (string) $localRaw;
        }

        // auth.his.kz хранит sha256 от секрета и подписывает именно им (AuthController::notifyExternalSystemsLogout).
        $key = hash('sha256', $secret);

        foreach ([true, false] as $numeric) {
            foreach ($localVariants as $local) {
                $payload = json_encode([
                    'action' => $input['action'],
                    'user_id' => $numeric ? (int) $input['user_id'] : $input['user_id'],
                    'local_user_id' => $local,
                    'timestamp' => $numeric ? (int) $input['timestamp'] : $input['timestamp'],
                ]);

                if (hash_equals(hash_hmac('sha256', $payload, $key), $received)) {
                    return true;
                }
            }
        }

        return false;
    }
}
