<?php

namespace App\Mail;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Решение редакции по рецепту читателя: опубликован или возвращён на доработку. */
class RecipeModeratedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Recipe $recipe,
        public bool $published,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->published
            ? "Рецепт «{$this->recipe->title}» опубликован"
            : "Рецепт «{$this->recipe->title}» вернули на доработку");
    }

    public function content(): Content
    {
        $site = rtrim((string) config('food_guru.site_url'), '/');

        return new Content(
            markdown: 'mail.recipe-moderated',
            with: [
                'name' => $this->user->name,
                'title' => $this->recipe->title,
                'published' => $this->published,
                'note' => $this->recipe->moderation_note,
                'url' => $this->published
                    ? "{$site}/recipes/{$this->recipe->slug}"
                    : "{$site}/account/recipes/{$this->recipe->id}",
            ],
        );
    }
}
