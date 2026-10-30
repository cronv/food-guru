<?php

namespace App\Http\Resources;

use App\Models\JournalIssue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Карточка выпуска для списка.
 * Пути к файлу здесь нет: PDF отдаётся только маршрутом скачивания.
 *
 * @mixin JournalIssue
 */
class JournalIssueCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'lead' => $this->lead,
            'period' => $this->period->value,
            'period_label' => $this->period->label(),
            'period_range' => $this->periodLabel(),
            'issue_date' => $this->issue_date->toDateString(),
            'issue_year' => $this->issue_year,
            'issue_month' => $this->issue_month,
            'status' => $this->status->value,
            'pages' => $this->pages,
            'file_size' => $this->file_size,
            'has_file' => $this->hasFile(),
            'downloads_count' => $this->downloads_count,
            'published_at' => $this->published_at?->toIso8601String(),
            'cover' => $this->whenLoaded('cover', fn () => $this->cover ? new MediaResource($this->cover) : null),
        ];
    }
}
