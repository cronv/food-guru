<?php

namespace App\Models;

use App\Enums\RatingSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property RatingSource $source
 * @property int $points
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $dedup_key
 * @property Carbon $created_at
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatingEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatingEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatingEvent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatingEvent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatingEvent whereDedupKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatingEvent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatingEvent wherePoints($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatingEvent whereSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatingEvent whereSubjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatingEvent whereSubjectType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RatingEvent whereUserId($value)
 *
 * @mixin \Eloquent
 */
class RatingEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'source',
        'points',
        'subject_type',
        'subject_id',
        'dedup_key',
    ];

    protected function casts(): array
    {
        return [
            'source' => RatingSource::class,
            'points' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
