<?php

namespace App\Http\Resources;

use App\Models\Contest;
use App\Models\ContestEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Полная карточка конкурса: условия, сроки, победители и состояние посетителя.
 *
 * @mixin Contest
 */
class ContestResource extends ContestCardResource
{
    /**
     * Состояние конкретного посетителя. Приходит из контроллера отдельно,
     * а не считается здесь: ресурс не должен ходить в базу — иначе список
     * из двадцати конкурсов превратится в двадцать лишних запросов.
     *
     * @var array{can_submit?: bool, can_vote?: bool, entries_left?: int,
     *            voted_entry_id?: int|null, my_entries?: iterable<ContestEntry>}
     */
    public array $viewer = [];

    public function withViewer(array $state): static
    {
        $this->viewer = $state;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $phase = $this->phase();

        return array_merge(parent::toArray($request), [
            'description' => $this->description,
            'rules' => $this->rules,
            'phase_hint' => $phase->hint(),
            'max_entries_per_user' => $this->max_entries_per_user,
            'accepts_entries' => $this->acceptsEntries(),
            'accepts_votes' => $this->acceptsVotes(),
            'moderation_note' => $this->when(
                $request->user()?->isStaff() ?? false,
                $this->moderation_note,
            ),
            'author' => $this->whenLoaded('author', fn () => [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ]),
            'winners' => ContestEntryResource::collection($this->whenLoaded('winners')),

            'viewer' => [
                'can_submit' => (bool) ($this->viewer['can_submit'] ?? false),
                'can_vote' => (bool) ($this->viewer['can_vote'] ?? false),
                'entries_left' => (int) ($this->viewer['entries_left'] ?? 0),
                'voted_entry_id' => $this->viewer['voted_entry_id'] ?? null,
                'my_entries' => ContestEntryResource::collection(
                    Collection::wrap($this->viewer['my_entries'] ?? [])
                ),
            ],
        ]);
    }
}
