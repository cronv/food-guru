<?php

namespace App\Models;

use App\Enums\AcademyFormat;
use App\Enums\AcademyLevel;
use App\Enums\PublishStatus;
use App\Support\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $author_id
 * @property int|null $cover_media_id
 * @property string $slug
 * @property string $title
 * @property string|null $lead
 * @property string|null $description
 * @property string|null $takeaways
 * @property AcademyFormat $format
 * @property AcademyLevel $level
 * @property PublishStatus $status
 * @property string|null $host_name
 * @property string|null $host_title
 * @property Carbon|null $starts_at
 * @property int|null $duration_minutes
 * @property string|null $stream_url
 * @property string|null $record_url
 * @property Carbon|null $published_at
 * @property-read int|null $registrations_count
 * @property string|null $moderation_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $author
 * @property-read Media|null $cover
 * @property-read Collection<int, AcademyRegistration> $registrations
 *
 * @method static Builder<static>|AcademySession newModelQuery()
 * @method static Builder<static>|AcademySession newQuery()
 * @method static Builder<static>|AcademySession published()
 * @method static Builder<static>|AcademySession query()
 * @method static Builder<static>|AcademySession search(?string $term)
 * @method static Builder<static>|AcademySession whereAuthorId($value)
 * @method static Builder<static>|AcademySession whereCoverMediaId($value)
 * @method static Builder<static>|AcademySession whereCreatedAt($value)
 * @method static Builder<static>|AcademySession whereDescription($value)
 * @method static Builder<static>|AcademySession whereDurationMinutes($value)
 * @method static Builder<static>|AcademySession whereFormat($value)
 * @method static Builder<static>|AcademySession whereHostName($value)
 * @method static Builder<static>|AcademySession whereHostTitle($value)
 * @method static Builder<static>|AcademySession whereId($value)
 * @method static Builder<static>|AcademySession whereLead($value)
 * @method static Builder<static>|AcademySession whereLevel($value)
 * @method static Builder<static>|AcademySession whereModerationNote($value)
 * @method static Builder<static>|AcademySession wherePublishedAt($value)
 * @method static Builder<static>|AcademySession whereRecordUrl($value)
 * @method static Builder<static>|AcademySession whereRegistrationsCount($value)
 * @method static Builder<static>|AcademySession whereSlug($value)
 * @method static Builder<static>|AcademySession whereStartsAt($value)
 * @method static Builder<static>|AcademySession whereStatus($value)
 * @method static Builder<static>|AcademySession whereStreamUrl($value)
 * @method static Builder<static>|AcademySession whereTakeaways($value)
 * @method static Builder<static>|AcademySession whereTitle($value)
 * @method static Builder<static>|AcademySession whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class AcademySession extends Model
{
    use Searchable;

    protected $fillable = [
        'author_id',
        'cover_media_id',
        'slug',
        'title',
        'lead',
        'description',
        'takeaways',
        'format',
        'level',
        'status',
        'host_name',
        'host_title',
        'starts_at',
        'duration_minutes',
        'stream_url',
        'record_url',
        'published_at',
        'moderation_note',
    ];

    protected function casts(): array
    {
        return [
            'format' => AcademyFormat::class,
            'level' => AcademyLevel::class,
            'status' => PublishStatus::class,
            'starts_at' => 'datetime',
            'published_at' => 'datetime',
            'duration_minutes' => 'integer',
            'registrations_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    /** @return HasMany<AcademyRegistration, $this> */
    public function registrations(): HasMany
    {
        return $this->hasMany(AcademyRegistration::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PublishStatus::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isPublic(): bool
    {
        return $this->status === PublishStatus::Published
            && $this->published_at !== null
            && $this->published_at->isPast();
    }

    public function endsAt(): ?Carbon
    {
        if (! $this->starts_at) {
            return null;
        }

        return $this->starts_at->copy()->addMinutes($this->duration_minutes ?? 60);
    }

    /** Эфир идёт прямо сейчас — показываем кнопку «смотреть». */
    public function isLiveNow(): bool
    {
        if (! $this->format->isLive() || ! $this->starts_at) {
            return false;
        }

        return $this->starts_at->isPast() && $this->endsAt()?->isFuture();
    }

    public function isUpcoming(): bool
    {
        return $this->format->isLive()
            && $this->starts_at !== null
            && $this->starts_at->isFuture();
    }

    /** Есть что смотреть: либо идёт эфир, либо выложена запись. */
    public function hasWatchable(): bool
    {
        return $this->isLiveNow() || filled($this->record_url);
    }
}
