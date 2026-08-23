<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OperatorInquiryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $senderName,
        public string $senderEmail,
        public string $subjectLine,
        public string $bodyText,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('【お問い合わせ】:subject', ['subject' => $this->subjectLine]).' - '.config('app.name'),
            replyTo: [
                new Address($this->senderEmail, $this->senderName),
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.operator-inquiry',
        );
    }
}
