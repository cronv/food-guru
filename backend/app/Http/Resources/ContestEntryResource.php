<?php

namespace App\Http\Resources;

use App\Models\ContestEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContestEntry */
class ContestEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isOwner = $viewer && $viewer->id === $this->user_id;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'votes_count' => $this->votes_count,
            'place' => $this->place,
            'created_at' => $this->created_at?->toIso8601String(),
            // Причину отклонения видит только сам автор и редакция.
            'reject_reason' => $this->when(
                $isOwner || ($viewer?->isStaff() ?? false),
                $this->reject_reason,
            ),
            'author' => $this->whenLoaded('author', fn () => [
                'id' => $this->author->id,
                'name' => $this->author->name,
                'rating_level' => $this->author->rating_level->value,
                'rating_label' => $this->author->rating_level->label(),
            ]),
            'media' => MediaResource::collection($this->whenLoaded('media')),
            'contest' => $this->whenLoaded('contest', fn () => [
                'slug' => $this->contest->slug,
                'title' => $this->contest->title,
            ]),
        ];
    }
}
