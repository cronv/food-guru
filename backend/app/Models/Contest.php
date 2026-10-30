<?php

namespace App\Models;

use App\Enums\ContestKind;
use App\Enums\ContestPhase;
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
 * @property string|null $rules
 * @property string|null $prize
 * @property ContestKind $kind
 * @property PublishStatus $status
 * @property Carbon|null $submissions_open_at
 * @property Carbon|null $submissions_close_at
 * @property Carbon|null $voting_open_at
 * @property Carbon|null $voting_close_at
 * @property Carbon|null $results_at
 * @property Carbon|null $published_at
 * @property int $max_entries_per_user
 * @property-read int|null $entries_count
 * @property-read int|null $votes_count
 * @property string|null $moderation_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $author
 * @property-read Media|null $cover
 * @property-read Collection<int, ContestEntry> $entries
 * @property-read Collection<int, ContestVote> $votes
 * @property-read Collection<int, ContestEntry> $winners
 * @property-read int|null $winners_count
 *
 * @method static Builder<static>|Contest newModelQuery()
 * @method static Builder<static>|Contest newQuery()
 * @method static Builder<static>|Contest published()
 * @method static Builder<static>|Contest query()
 * @method static Builder<static>|Contest search(?string $term)
 * @method static Builder<static>|Contest whereAuthorId($value)
 * @method static Builder<static>|Contest whereCoverMediaId($value)
 * @method static Builder<static>|Contest whereCreatedAt($value)
 * @method static Builder<static>|Contest whereDescription($value)
 * @method static Builder<static>|Contest whereEntriesCount($value)
 * @method static Builder<static>|Contest whereId($value)
 * @method static Builder<static>|Contest whereKind($value)
 * @method static Builder<static>|Contest whereLead($value)
 * @method static Builder<static>|Contest whereMaxEntriesPerUser($value)
 * @method static Builder<static>|Contest whereModerationNote($value)
 * @method static Builder<static>|Contest wherePrize($value)
 * @method static Builder<static>|Contest wherePublishedAt($value)
 * @method static Builder<static>|Contest whereResultsAt($value)
 * @method static Builder<static>|Contest whereRules($value)
 * @method static Builder<static>|Contest whereSlug($value)
 * @method static Builder<static>|Contest whereStatus($value)
 * @method static Builder<static>|Contest whereSubmissionsCloseAt($value)
 * @method static Builder<static>|Contest whereSubmissionsOpenAt($value)
 * @method static Builder<static>|Contest whereTitle($value)
 * @method static Builder<static>|Contest whereUpdatedAt($value)
 * @method static Builder<static>|Contest whereVotesCount($value)
 * @method static Builder<static>|Contest whereVotingCloseAt($value)
 * @method static Builder<static>|Contest whereVotingOpenAt($value)
 *
 * @mixin \Eloquent
 */
class Contest extends Model
{
    use Searchable;

    protected $fillable = [
        'author_id',
        'cover_media_id',
        'slug',
        'title',
        'lead',
        'description',
        'rules',
        'prize',
        'kind',
        'status',
        'submissions_open_at',
        'submissions_close_at',
        'voting_open_at',
        'voting_close_at',
        'results_at',
        'published_at',
        'max_entries_per_user',
        'moderation_note',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ContestKind::class,
            'status' => PublishStatus::class,
            'submissions_open_at' => 'datetime',
            'submissions_close_at' => 'datetime',
            'voting_open_at' => 'datetime',
            'voting_close_at' => 'datetime',
            'results_at' => 'datetime',
            'published_at' => 'datetime',
            'max_entries_per_user' => 'integer',
            'entries_count' => 'integer',
            'votes_count' => 'integer',
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

    /** @return HasMany<ContestEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(ContestEntry::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ContestVote::class);
    }

    /** Победители: места с первого по третье, по возрастанию места. */
    public function winners(): HasMany
    {
        return $this->hasMany(ContestEntry::class)
            ->whereNotNull('place')
            ->orderBy('place');
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

    /**
     * Фаза считается из дат, а не хранится.
     * Даты могут быть не заполнены — тогда конкурс просто «скоро старт».
     */
    public function phase(): ContestPhase
    {
        $now = now();

        if ($this->results_at && $this->results_at->lte($now)) {
            return ContestPhase::Finished;
        }

        if ($this->voting_close_at && $this->voting_close_at->lte($now)) {
            return ContestPhase::Judging;
        }

        if ($this->voting_open_at && $this->voting_open_at->lte($now)) {
            return ContestPhase::Voting;
        }

        if ($this->submissions_close_at && $this->submissions_close_at->lte($now)) {
            return ContestPhase::Judging;
        }

        if ($this->submissions_open_at && $this->submissions_open_at->lte($now)) {
            return ContestPhase::Submissions;
        }

        return ContestPhase::Announced;
    }

    public function acceptsEntries(): bool
    {
        return $this->isPublic() && $this->phase() === ContestPhase::Submissions;
    }

    public function acceptsVotes(): bool
    {
        return $this->isPublic() && $this->phase() === ContestPhase::Voting;
    }

    /** Ближайшая дата, к которой стоит показать обратный отсчёт. */
    public function deadlineAt(): ?Carbon
    {
        return match ($this->phase()) {
            ContestPhase::Announced => $this->submissions_open_at,
            ContestPhase::Submissions => $this->submissions_close_at,
            ContestPhase::Voting => $this->voting_close_at,
            ContestPhase::Judging => $this->results_at,
            ContestPhase::Finished => null,
        };
    }
}
