<?php

namespace App\Http\Resources;

use App\Models\AcademySession;
use Illuminate\Http\Request;

/**
 * Полная карточка занятия. Ссылки отдаются только тем, кто записан.
 *
 * @mixin AcademySession
 */
class AcademyResource extends AcademyCardResource
{
    /** @var array{registered?: bool, can_watch?: bool, registered_at?: string|null} */
    public array $viewer = [];

    public function withViewer(array $state): static
    {
        $this->viewer = $state;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $canWatch = (bool) ($this->viewer['can_watch'] ?? false);
        $isStaff = $request->user()?->isStaff() ?? false;

        return array_merge(parent::toArray($request), [
            'description' => $this->description,
            // Пункты «чему научимся» хранятся строками, по одной в строке.
            'takeaways' => collect(preg_split('/\R/', (string) $this->takeaways))
                ->map(fn (string $line) => trim($line))
                ->filter()
                ->values(),
            'moderation_note' => $this->when($isStaff, $this->moderation_note),
            'author' => $this->whenLoaded('author', fn () => [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ]),

            /*
             * Ссылки появляются в ответе, только если смотреть действительно можно.
             * Ссылка на эфир — пока эфир идёт: до начала она посетителю бесполезна,
             * а утечь успеет.
             *
             * Редакции обе ссылки видны всегда и независимо от времени: иначе
             * редактор открыл бы форму занятия и увидел пустые поля, а при
             * сохранении затёр бы то, что уже было записано.
             */
            'stream_url' => $this->when(
                $isStaff || ($canWatch && $this->isLiveNow()),
                $this->stream_url,
            ),
            'record_url' => $this->when($isStaff || $canWatch, $this->record_url),

            'viewer' => [
                'registered' => (bool) ($this->viewer['registered'] ?? false),
                'can_watch' => $canWatch,
                'registered_at' => $this->viewer['registered_at'] ?? null,
            ],
        ]);
    }
}
