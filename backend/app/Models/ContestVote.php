<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $contest_id
 * @property int $contest_entry_id
 * @property int $user_id
 * @property Carbon $created_at
 * @property-read Contest $contest
 * @property-read ContestEntry $entry
 * @property-read User $voter
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContestVote newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContestVote newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContestVote query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContestVote whereContestEntryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContestVote whereContestId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContestVote whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContestVote whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContestVote whereUserId($value)
 *
 * @mixin \Eloquent
 */
class ContestVote extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'contest_id',
        'contest_entry_id',
        'user_id',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ContestEntry::class, 'contest_entry_id');
    }

    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    public function voter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
