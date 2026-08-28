<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegistrationApplicationReceivedAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $planLabel,
        public string $displayName,
        public string $email,
        public string $organizationName,
        public string $phone,
        public string $message,
        public string $adminUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('【利用申請】:plan — :name', [
                'plan' => $this->planLabel,
                'name' => $this->displayName,
            ]).' - '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.registration-application-received-admin');
    }
}
