<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LoginLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Ссылка для входа');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.login',
            with: [
                'name' => $this->user->name,
                'url' => $this->url,
                'minutes' => config('food_guru.tokens.login_ttl_minutes'),
            ],
        );
    }
}
