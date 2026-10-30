<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $recipe_id
 * @property int $user_id
 * @property int $rating
 * @property string $body
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $author
 * @property-read Collection<int, Media> $media
 * @property-read int|null $media_count
 * @property-read Recipe $recipe
 *
 * @method static Builder<static>|Review newModelQuery()
 * @method static Builder<static>|Review newQuery()
 * @method static Builder<static>|Review query()
 * @method static Builder<static>|Review visible()
 * @method static Builder<static>|Review whereBody($value)
 * @method static Builder<static>|Review whereCreatedAt($value)
 * @method static Builder<static>|Review whereId($value)
 * @method static Builder<static>|Review whereRating($value)
 * @method static Builder<static>|Review whereRecipeId($value)
 * @method static Builder<static>|Review whereStatus($value)
 * @method static Builder<static>|Review whereUpdatedAt($value)
 * @method static Builder<static>|Review whereUserId($value)
 *
 * @mixin \Eloquent
 */
class Review extends Model
{
    protected $fillable = [
        'recipe_id',
        'user_id',
        'rating',
        'body',
        'status',
    ];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'review_media')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }
}
