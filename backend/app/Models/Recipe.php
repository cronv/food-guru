<?php

namespace App\Models;

use App\Enums\RecipeStatus;
use App\Support\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $author_id
 * @property int|null $category_id
 * @property int|null $cover_media_id
 * @property string|null $reel_path
 * @property int $views_count
 * @property int $likes_count
 * @property int $dislikes_count
 * @property string $slug
 * @property string $title
 * @property string|null $lead
 * @property int|null $time_minutes
 * @property int $servings
 * @property string $difficulty
 * @property RecipeStatus $status
 * @property Carbon|null $published_at
 * @property string|null $og_description
 * @property int|null $calories
 * @property int|null $protein
 * @property int|null $fat
 * @property int|null $carbs
 * @property bool $is_vegetarian
 * @property bool $is_gluten_free
 * @property string|null $moderation_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $author
 * @property-read Category|null $category
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Collection> $collections
 * @property-read int|null $collections_count
 * @property-read Media|null $cover
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $favoredBy
 * @property-read int|null $favored_by_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Media> $gallery
 * @property-read int|null $gallery_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RecipeIngredient> $ingredients
 * @property-read int|null $ingredients_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RecipeReaction> $reactions
 * @property-read int|null $reactions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Review> $reviews
 * @property-read int|null $reviews_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $savedBy
 * @property-read int|null $saved_by_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RecipeStep> $steps
 * @property-read int|null $steps_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Tag> $tags
 * @property-read int|null $tags_count
 * @property bool|null $is_favorite вычисляется в RecipeController::show для вошедшего
 * @property bool|null $in_cookbook вычисляется в RecipeController::show
 * @property string|null $my_reaction вычисляется в RecipeController::show
 * @property-read int|null $saves_count  withCount(savedBy as saves_count)
 * @property-read float|null $reviews_avg_rating  withAvg(reviews, rating)
 *
 * @method static Builder<static>|Recipe newModelQuery()
 * @method static Builder<static>|Recipe newQuery()
 * @method static Builder<static>|Recipe published()
 * @method static Builder<static>|Recipe query()
 * @method static Builder<static>|Recipe search(?string $term)
 * @method static Builder<static>|Recipe whereAuthorId($value)
 * @method static Builder<static>|Recipe whereCalories($value)
 * @method static Builder<static>|Recipe whereCarbs($value)
 * @method static Builder<static>|Recipe whereCategoryId($value)
 * @method static Builder<static>|Recipe whereCoverMediaId($value)
 * @method static Builder<static>|Recipe whereCreatedAt($value)
 * @method static Builder<static>|Recipe whereDifficulty($value)
 * @method static Builder<static>|Recipe whereDislikesCount($value)
 * @method static Builder<static>|Recipe whereFat($value)
 * @method static Builder<static>|Recipe whereId($value)
 * @method static Builder<static>|Recipe whereIsGlutenFree($value)
 * @method static Builder<static>|Recipe whereIsVegetarian($value)
 * @method static Builder<static>|Recipe whereLead($value)
 * @method static Builder<static>|Recipe whereLikesCount($value)
 * @method static Builder<static>|Recipe whereModerationNote($value)
 * @method static Builder<static>|Recipe whereOgDescription($value)
 * @method static Builder<static>|Recipe whereProtein($value)
 * @method static Builder<static>|Recipe wherePublishedAt($value)
 * @method static Builder<static>|Recipe whereReelPath($value)
 * @method static Builder<static>|Recipe whereServings($value)
 * @method static Builder<static>|Recipe whereSlug($value)
 * @method static Builder<static>|Recipe whereStatus($value)
 * @method static Builder<static>|Recipe whereTimeMinutes($value)
 * @method static Builder<static>|Recipe whereTitle($value)
 * @method static Builder<static>|Recipe whereUpdatedAt($value)
 * @method static Builder<static>|Recipe whereViewsCount($value)
 * @method static Builder<static>|Recipe withReel()
 *
 * @mixin \Eloquent
 */
class Recipe extends Model
{
    use HasFactory, Searchable;

    protected $fillable = [
        'author_id',
        'category_id',
        'cover_media_id',
        'reel_path',
        'slug',
        'title',
        'lead',
        'time_minutes',
        'servings',
        'difficulty',
        'status',
        'published_at',
        'og_description',
        'calories',
        'protein',
        'fat',
        'carbs',
        'is_vegetarian',
        'is_gluten_free',
        'moderation_note',
    ];

    protected function casts(): array
    {
        return [
            'status' => RecipeStatus::class,
            'published_at' => 'datetime',
            'is_vegetarian' => 'boolean',
            'is_gluten_free' => 'boolean',
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class)->orderBy('position');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(RecipeStep::class)->orderBy('position');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /** Галерея: кадры сверх обложки. */
    public function gallery(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'recipe_media')
            ->withPivot('caption', 'position')
            ->orderByPivot('position');
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class)->withPivot('position');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(RecipeReaction::class);
    }

    public function favoredBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorites')->withPivot('created_at');
    }

    /** Кто сохранил рецепт в рецептосохранялку. */
    public function savedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'cookbook_recipes')->withPivot('created_at');
    }

    /** Всё, что видно в публичной части. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', RecipeStatus::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function reelUrl(): ?string
    {
        return $this->reel_path ? Storage::disk('public')->url($this->reel_path) : null;
    }

    public function scopeWithReel(Builder $query): Builder
    {
        return $query->whereNotNull('reel_path');
    }

    public function isPublic(): bool
    {
        return $this->status === RecipeStatus::Published
            && $this->published_at !== null
            && $this->published_at->isPast();
    }
}
