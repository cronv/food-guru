<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Материал закрытой академии: видео, мастер-класс, ресторанный рецепт или материал.
 *
 * @property int $id
 * @property string $slug
 * @property string $kind
 * @property string $title
 * @property string|null $lead
 * @property string|null $body
 * @property string|null $chef_name
 * @property int|null $duration_minutes
 * @property int|null $cover_media_id
 * @property string|null $video_disk
 * @property string|null $video_path
 * @property array<array-key, mixed>|null $data
 * @property bool $is_published
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Media|null $cover
 *
 * @method static Builder<static>|ClubItem newModelQuery()
 * @method static Builder<static>|ClubItem newQuery()
 * @method static Builder<static>|ClubItem published()
 * @method static Builder<static>|ClubItem query()
 * @method static Builder<static>|ClubItem whereBody($value)
 * @method static Builder<static>|ClubItem whereChefName($value)
 * @method static Builder<static>|ClubItem whereCoverMediaId($value)
 * @method static Builder<static>|ClubItem whereCreatedAt($value)
 * @method static Builder<static>|ClubItem whereData($value)
 * @method static Builder<static>|ClubItem whereDurationMinutes($value)
 * @method static Builder<static>|ClubItem whereId($value)
 * @method static Builder<static>|ClubItem whereIsPublished($value)
 * @method static Builder<static>|ClubItem whereKind($value)
 * @method static Builder<static>|ClubItem whereLead($value)
 * @method static Builder<static>|ClubItem wherePublishedAt($value)
 * @method static Builder<static>|ClubItem whereSlug($value)
 * @method static Builder<static>|ClubItem whereTitle($value)
 * @method static Builder<static>|ClubItem whereUpdatedAt($value)
 * @method static Builder<static>|ClubItem whereVideoDisk($value)
 * @method static Builder<static>|ClubItem whereVideoPath($value)
 *
 * @mixin \Eloquent
 */
class ClubItem extends Model
{
    public const KINDS = [
        'video' => 'Видеоурок',
        'masterclass' => 'Закрытый мастер-класс',
        'recipe' => 'Ресторанный рецепт',
        'material' => 'Материал',
    ];

    protected $fillable = [
        'slug', 'kind', 'title', 'lead', 'body', 'chef_name', 'duration_minutes',
        'cover_media_id', 'data', 'is_published', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'duration_minutes' => 'integer',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function hasVideo(): bool
    {
        return $this->video_path !== null && $this->video_disk !== null;
    }

    /** Карточка: видна всем, чтобы было понятно, что внутри подписки. */
    public function toCard(bool $unlocked): array
    {
        return [
            'slug' => $this->slug,
            'kind' => $this->kind,
            'kind_label' => self::KINDS[$this->kind] ?? $this->kind,
            'title' => $this->title,
            'lead' => $this->lead,
            'chef_name' => $this->chef_name,
            'duration_minutes' => $this->duration_minutes,
            'has_video' => $this->hasVideo(),
            'cover' => $this->cover ? ['url' => $this->cover->url(), 'alt' => $this->cover->alt] : null,
            'unlocked' => $unlocked,
        ];
    }
}
