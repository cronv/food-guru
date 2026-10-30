<?php

namespace App\Http\Resources;

use App\Models\Contest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Короткая форма для списка конкурсов и архива.
 *
 * @mixin Contest
 */
class ContestCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $phase = $this->phase();

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'lead' => $this->lead,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'status' => $this->status->value,
            'phase' => $phase->value,
            'phase_label' => $phase->label(),
            'is_archive' => $phase->isArchive(),
            'prize' => $this->prize,
            'entries_count' => $this->entries_count,
            'votes_count' => $this->votes_count,
            'submissions_open_at' => $this->submissions_open_at?->toIso8601String(),
            'submissions_close_at' => $this->submissions_close_at?->toIso8601String(),
            'voting_open_at' => $this->voting_open_at?->toIso8601String(),
            'voting_close_at' => $this->voting_close_at?->toIso8601String(),
            'results_at' => $this->results_at?->toIso8601String(),
            'deadline_at' => $this->deadlineAt()?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'cover' => $this->whenLoaded('cover', fn () => $this->cover ? new MediaResource($this->cover) : null),
        ];
    }
}
