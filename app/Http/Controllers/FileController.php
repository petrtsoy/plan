<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Файлы к записям (акт ТО, свидетельство о поверке, фото): лежат в storage/app/private,
 * отдаются только вошедшим. Небезопасные для браузера типы – скачиванием (Attachment::INLINE).
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
}
