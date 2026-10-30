<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * «С вашим аккаунтом сделали X — если это не вы, …». Приходит на адрес владельца
 * после смены пароля и смены почты: перехваченную сессию так быстро заметят.
 */
class SecurityNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $title,
        public string $text,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.security-notice',
            with: [
                'name' => $this->name,
                'text' => $this->text,
                'loginUrl' => rtrim((string) config('food_guru.site_url'), '/').'/login',
            ],
        );
    }
}
