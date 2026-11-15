<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Ежедневная сводка сигналов: просроченные и близкие сроки ТО, гарантия, договоры. */
class MaintenanceDigest extends Mailable
{
    public function __construct(public array $digest) {}

    public function envelope(): Envelope
    {
        $n = count($this->digest['overdue']) + count($this->digest['soon']);

        return new Envelope(subject: sprintf(
            '%s: ТО и поверка – %s',
            config('app.name'),
            $n ? "сроков {$n}" : 'сводка',
        ));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.maintenance-digest', with: $this->digest);
    }
}
