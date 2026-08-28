<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegistrationApplicationApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $displayName,
        public string $planLabel,
        public string $activateUrl,
        public string $expiresAt,
        public bool $isStandard = false,
        public bool $isTenant = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('利用申請が承認されました。パスワードを設定してください').' - '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.registration-application-approved');
    }
}
