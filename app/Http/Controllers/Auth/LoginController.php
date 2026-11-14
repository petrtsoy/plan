<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login', [
            'ssoEnabled' => config('services.sso.enabled') && config('services.sso.client_id'),
            'devUsers' => self::devLoginAllowed() ? User::where('is_active', true)->orderBy('name')->get() : collect(),
        ]);
    }

    /** Вход без SSO – только в локальной разработке. */
    public function dev(Request $request): RedirectResponse
    {
        abort_unless(self::devLoginAllowed(), 404);

        $user = User::where('is_active', true)->findOrFail($request->integer('user_id'));
        SsoController::login($request, $user);

        return redirect()->route('dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Audit::auth('logout', Auth::id());
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public static function devLoginAllowed(): bool
    {
        return app()->environment('local', 'testing');
    }
}
