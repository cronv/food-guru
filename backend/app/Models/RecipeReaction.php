<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $recipe_id
 * @property int $user_id
 * @property string $reaction
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Recipe $recipe
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeReaction newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeReaction newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeReaction query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeReaction whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeReaction whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeReaction whereReaction($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeReaction whereRecipeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeReaction whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeReaction whereUserId($value)
 *
 * @mixin \Eloquent
 */
class RecipeReaction extends Model
{
    public const LIKE = 'like';

    public const DISLIKE = 'dislike';

    protected $fillable = ['recipe_id', 'user_id', 'reaction'];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
