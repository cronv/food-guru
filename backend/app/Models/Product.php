<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string|null $category
 * @property string|null $material
 * @property string|null $country
 * @property string|null $color
 * @property string|null $lead
 * @property string|null $description
 * @property string|null $sku
 * @property int $price
 * @property int|null $old_price
 * @property int $stock
 * @property int|null $cover_media_id
 * @property bool $is_published
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Media|null $cover
 *
 * @method static Builder<static>|Product newModelQuery()
 * @method static Builder<static>|Product newQuery()
 * @method static Builder<static>|Product published()
 * @method static Builder<static>|Product query()
 * @method static Builder<static>|Product whereCategory($value)
 * @method static Builder<static>|Product whereColor($value)
 * @method static Builder<static>|Product whereCountry($value)
 * @method static Builder<static>|Product whereCoverMediaId($value)
 * @method static Builder<static>|Product whereCreatedAt($value)
 * @method static Builder<static>|Product whereDescription($value)
 * @method static Builder<static>|Product whereId($value)
 * @method static Builder<static>|Product whereIsPublished($value)
 * @method static Builder<static>|Product whereLead($value)
 * @method static Builder<static>|Product whereMaterial($value)
 * @method static Builder<static>|Product whereOldPrice($value)
 * @method static Builder<static>|Product wherePrice($value)
 * @method static Builder<static>|Product whereSku($value)
 * @method static Builder<static>|Product whereSlug($value)
 * @method static Builder<static>|Product whereStock($value)
 * @method static Builder<static>|Product whereTitle($value)
 * @method static Builder<static>|Product whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Product extends Model
{
    protected $fillable = [
        'slug', 'title', 'category', 'material', 'country', 'color', 'lead', 'description', 'sku',
        'price', 'old_price', 'stock', 'cover_media_id', 'is_published',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'old_price' => 'integer',
            'stock' => 'integer',
            'is_published' => 'boolean',
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

    public function toApi(bool $full = false): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'category' => $this->category,
            'material' => $this->material,
            'country' => $this->country,
            'color' => $this->color,
            'lead' => $this->lead,
            'price' => $this->price,
            'old_price' => $this->old_price,
            'in_stock' => $this->stock > 0,
            // Точный остаток покупателю не нужен — только «мало» или «есть».
            'stock_left' => $this->stock > 0 && $this->stock <= 5 ? $this->stock : null,
            'cover' => $this->cover ? ['url' => $this->cover->url(), 'alt' => $this->cover->alt] : null,
        ] + ($full ? ['description' => $this->description, 'sku' => $this->sku] : []);
    }
}
