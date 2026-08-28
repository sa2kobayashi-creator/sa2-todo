<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LightDormantWarningMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $displayName,
        public string $loginUrl,
        public string $deleteAfterDate,
        public int $inactiveDays,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('【重要】お試しアカウントの確認をお願いします').' - '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.light-dormant-warning');
    }
}
