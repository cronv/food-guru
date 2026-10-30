<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $recipe_id
 * @property int|null $media_id
 * @property int $position
 * @property string $body
 * @property string|null $hint
 * @property string|null $tip
 * @property string|null $substitution
 * @property string|null $chef_secret
 * @property-read Media|null $media
 * @property-read Recipe $recipe
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeStep newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeStep newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeStep query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeStep whereBody($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeStep whereChefSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeStep whereHint($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeStep whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeStep whereMediaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeStep wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeStep whereRecipeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeStep whereSubstitution($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeStep whereTip($value)
 *
 * @mixin \Eloquent
 */
class RecipeStep extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'recipe_id',
        'media_id',
        'position',
        'body',
        'hint',
        'tip',
        'substitution',
        'chef_secret',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
