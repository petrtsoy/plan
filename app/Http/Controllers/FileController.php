<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Attachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Файлы к записям (акт ТО, свидетельство о поверке, фото, паспорт): лежат в storage/app/private,
 * отдаются только вошедшим. Небезопасные для браузера типы – скачиванием (Attachment::INLINE).
 * Убирают файл мягко: запись помечается, файл на диске остается.
 */
class FileController extends Controller
{
    public function show(Attachment $attachment): BinaryFileResponse
    {
        $path = Storage::disk('local')->path($attachment->path);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Disposition' => sprintf(
            '%s; filename="%s"',
            in_array($attachment->mime, Attachment::INLINE, true) ? 'inline' : 'attachment',
            addslashes($attachment->name),
        )]);
    }

    /** Файл к карточке единицы: фото, паспорт, регистрационное удостоверение, акт. */
    public function store(Request $request, Asset $asset): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:32768'],
            'kind' => ['required', 'string', 'in:'.implode(',', array_keys(Attachment::KINDS))],
            'name' => ['nullable', 'string', 'max:191'],
        ], ['file.max' => 'Файл больше 32 МБ.']);

        $file = $request->file('file');
        $ext = Str::lower($file->getClientOriginalExtension());
        $path = $file->storeAs('attachments/'.$asset->getMorphClass().'/'.$asset->id,
            now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).($ext ? '.'.$ext : ''), 'local');

        $asset->attachments()->create([
            'kind' => $request->string('kind'),
            'name' => $request->filled('name')
                ? $request->string('name')->limit(191, '')->toString()
                : mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'user_id' => Auth::id(),
        ]);

        return back()->with('ok', 'Файл загружен.');
    }

    public function destroy(Attachment $attachment): RedirectResponse
    {
        $attachment->delete();

        return back()->with('ok', 'Файл убран из карточки (сам файл сохранен).');
    }
}
