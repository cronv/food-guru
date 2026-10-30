<?php

namespace App\Http\Resources;

use App\Models\AcademySession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Короткая форма занятия для расписания и списка записей.
 * Ссылок на эфир и запись здесь нет и быть не должно: список открыт всем,
 * а смотреть можно только по записи.
 *
 * @mixin AcademySession
 */
class AcademyCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'lead' => $this->lead,
            'format' => $this->format->value,
            'format_label' => $this->format->label(),
            'level' => $this->level->value,
            'level_label' => $this->level->label(),
            'status' => $this->status->value,
            'host_name' => $this->host_name,
            'host_title' => $this->host_title,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->endsAt()?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'is_live_now' => $this->isLiveNow(),
            'is_upcoming' => $this->isUpcoming(),
            'has_record' => filled($this->record_url),
            'registrations_count' => $this->registrations_count,
            'published_at' => $this->published_at?->toIso8601String(),
            'cover' => $this->whenLoaded('cover', fn () => $this->cover ? new MediaResource($this->cover) : null),
        ];
    }
}
