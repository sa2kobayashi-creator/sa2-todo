<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailChangedNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $displayName,
        public string $previousEmail,
        public string $newEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('メールアドレスが変更されました').' - '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.email-changed-notice',
        );
    }
}
