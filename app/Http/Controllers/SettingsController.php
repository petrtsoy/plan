<?php

namespace App\Http\Controllers;

use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** Раздел "Настройки": ключи интеграций, адреса, почта, оформление. Только администратор. */
class SettingsController extends Controller
{
    public function index(): View
    {
        return view('admin.settings', ['groups' => Settings::form()]);
    }

    public function update(Request $request, string $group): RedirectResponse
    {
        abort_unless(isset(Settings::GROUPS[$group]), 404);
        $request->validate(Settings::rules($group));

        $changed = Settings::save($group, $request);

        return redirect()->to(route('admin.settings').'#'.$group)
            ->with('ok', $changed ? Settings::GROUPS[$group].': сохранено.' : 'Изменений нет.');
    }

    public function import(): RedirectResponse
    {
        $n = Settings::import();

        return back()->with('ok', $n ? "Перенесено в БД из .env: {$n}." : 'Все настройки уже в БД.');
    }

    /** Логотип – без входа: он нужен и на странице входа. */
    public function logo(): Response
    {
        [$mime, $bytes] = Settings::logo() ?? abort(404);

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            // SVG открытый напрямую не должен исполнять скрипты.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
