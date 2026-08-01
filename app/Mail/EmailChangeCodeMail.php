<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailChangeCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $displayName,
        public string $newEmail,
        public string $code,
        public int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('メールアドレス変更の確認コード').' - '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.email-change-code',
        );
    }
}
