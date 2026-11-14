<?php

namespace App\Http\Middleware;

use App\Enums\Area;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * role:admin – только администратор; role:plans, role:assets, role:inventory –
 * кто правит этот раздел (несколько через запятую – хоть один из них).
 * Отключенного пользователя выкидываем на любом запросе: отключение должно
 * действовать сразу, а не после истечения сессии.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$levels): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login')->with('error', 'Учетная запись отключена.');
        }

        $allowed = match (true) {
            $levels === [] => true,
            $levels === ['admin'] => $user->isAdmin(),
            default => $user->editsAny(...array_map(fn ($l) => Area::from($l), $levels)),
        };

        abort_unless($allowed, 403, 'Недостаточно прав.');

        return $next($request);
    }
}
