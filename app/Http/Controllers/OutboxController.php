<?php

namespace App\Http\Controllers;

use App\Models\OutboxMessage;
use App\Services\OutboxSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** "Что не ушло" в 1С и it.his.kz: причина, число попыток, повтор вручную. Только администратору. */
class OutboxController extends Controller
{
    public function index(OutboxSender $sender): View
    {
        return view('admin.outbox', [
            'messages' => $sender->stuck(),
            'waiting' => OutboxMessage::where('status', 'pending')->count(),
            'sent' => OutboxMessage::where('status', 'sent')->count(),
        ]);
    }

    public function retry(OutboxMessage $message, OutboxSender $sender): RedirectResponse
    {
        $sender->retry($message);

        return back()->with('ok', "Сообщение #{$message->id} – в очередь заново.");
    }
}
