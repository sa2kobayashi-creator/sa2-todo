<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeInitialPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $displayName,
        public string $email,
        public string $initialPassword,
        public string $loginUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('会員登録が完了しました（初期パスワードのお知らせ）').' - '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.welcome-initial-password',
        );
    }
}
