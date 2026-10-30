<?php

namespace App\Models;

use App\Enums\EntryStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $contest_id
 * @property int $user_id
 * @property string|null $title
 * @property string|null $body
 * @property EntryStatus $status
 * @property string|null $reject_reason
 * @property-read int|null $votes_count
 * @property int|null $place
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $author
 * @property-read Contest $contest
 * @property-read Collection<int, Media> $media
 * @property-read int|null $media_count
 * @property-read Collection<int, ContestVote> $votes
 *
 * @method static Builder<static>|ContestEntry approved()
 * @method static Builder<static>|ContestEntry newModelQuery()
 * @method static Builder<static>|ContestEntry newQuery()
 * @method static Builder<static>|ContestEntry query()
 * @method static Builder<static>|ContestEntry whereBody($value)
 * @method static Builder<static>|ContestEntry whereContestId($value)
 * @method static Builder<static>|ContestEntry whereCreatedAt($value)
 * @method static Builder<static>|ContestEntry whereId($value)
 * @method static Builder<static>|ContestEntry wherePlace($value)
 * @method static Builder<static>|ContestEntry whereRejectReason($value)
 * @method static Builder<static>|ContestEntry whereStatus($value)
 * @method static Builder<static>|ContestEntry whereTitle($value)
 * @method static Builder<static>|ContestEntry whereUpdatedAt($value)
 * @method static Builder<static>|ContestEntry whereUserId($value)
 * @method static Builder<static>|ContestEntry whereVotesCount($value)
 *
 * @mixin \Eloquent
 */
class ContestEntry extends Model
{
    protected $fillable = [
        'contest_id',
        'user_id',
        'title',
        'body',
        'status',
        'reject_reason',
        'place',
    ];

    protected function casts(): array
    {
        return [
            'status' => EntryStatus::class,
            'votes_count' => 'integer',
            'place' => 'integer',
        ];
    }

    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ContestVote::class);
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'contest_entry_media')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    /** В публичной галерее видны только принятые работы. */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', EntryStatus::Approved);
    }
}
