<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Attachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Файлы к карточке: фото, паспорт, регистрационное удостоверение, свидетельство о поверке.
 * Лежат в storage/app/private/attachments, отдаются только вошедшим. Удаление – мягкое:
 * запись помечается, файл на диске остается (по правилам проекта ничего не удаляем).
 */
class AttachmentController extends Controller
{
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

    /** Картинки и PDF – в браузере, остальное – скачиванием (svg и html показывать опасно). */
    public function show(Attachment $attachment): StreamedResponse
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($attachment->path), 404);

        $inline = in_array($attachment->mime, Attachment::INLINE, true);

        return $disk->response($attachment->path, $attachment->name, [
            'Content-Type' => $attachment->mime ?: 'application/octet-stream',
        ], $inline ? 'inline' : 'attachment');
    }

    public function destroy(Attachment $attachment): RedirectResponse
    {
        $attachment->delete();

        return back()->with('ok', 'Файл убран из карточки (сам файл сохранен).');
    }
}
