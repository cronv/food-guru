<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifyEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Подтвердите адрес почты');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.verify',
            with: [
                'name' => $this->user->name,
                'url' => $this->url,
                'hours' => config('food_guru.tokens.verify_ttl_hours'),
            ],
        );
    }
}
