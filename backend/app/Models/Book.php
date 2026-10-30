<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string|null $author_name
 * @property int|null $year
 * @property string|null $country
 * @property string|null $lead
 * @property string|null $description
 * @property int|null $cover_media_id
 * @property int $price
 * @property bool $in_subscription
 * @property bool $is_published
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, BookChapter> $chapters
 * @property-read int|null $chapters_count
 * @property-read Media|null $cover
 * @property-read Collection<int, BookPurchase> $purchases
 * @property-read int|null $purchases_count
 *
 * @method static Builder<static>|Book newModelQuery()
 * @method static Builder<static>|Book newQuery()
 * @method static Builder<static>|Book published()
 * @method static Builder<static>|Book query()
 * @method static Builder<static>|Book whereAuthorName($value)
 * @method static Builder<static>|Book whereCountry($value)
 * @method static Builder<static>|Book whereCoverMediaId($value)
 * @method static Builder<static>|Book whereCreatedAt($value)
 * @method static Builder<static>|Book whereDescription($value)
 * @method static Builder<static>|Book whereId($value)
 * @method static Builder<static>|Book whereInSubscription($value)
 * @method static Builder<static>|Book whereIsPublished($value)
 * @method static Builder<static>|Book whereLead($value)
 * @method static Builder<static>|Book wherePrice($value)
 * @method static Builder<static>|Book wherePublishedAt($value)
 * @method static Builder<static>|Book whereSlug($value)
 * @method static Builder<static>|Book whereTitle($value)
 * @method static Builder<static>|Book whereUpdatedAt($value)
 * @method static Builder<static>|Book whereYear($value)
 *
 * @mixin \Eloquent
 */
class Book extends Model
{
    protected $fillable = [
        'slug', 'title', 'author_name', 'year', 'country', 'lead', 'description', 'cover_media_id',
        'price', 'in_subscription', 'is_published', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'year' => 'integer',
            'in_subscription' => 'boolean',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(BookChapter::class)->orderBy('position');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(BookPurchase::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function isFree(): bool
    {
        return $this->price === 0;
    }
}
