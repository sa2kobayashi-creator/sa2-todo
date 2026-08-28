<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegistrationApplicationRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $displayName,
        public string $planLabel,
        public string $adminNote = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('利用申請の結果について').' - '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.registration-application-rejected');
    }
}
