<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $recipe_id
 * @property int $user_id
 * @property string $reason
 * @property string|null $message
 * @property string $status
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Recipe $recipe
 * @property-read User|null $resolver
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeReport newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeReport newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeReport query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeReport whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeReport whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeReport whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeReport whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeReport whereRecipeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeReport whereResolvedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeReport whereResolvedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeReport whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeReport whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecipeReport whereUserId($value)
 *
 * @mixin \Eloquent
 */
class RecipeReport extends Model
{
    /** Причины — те же, что в форме на сайте (REPORT_REASONS). */
    public const REASONS = [
        'error' => 'Ошибка в рецепте',
        'amounts' => 'Неверные граммовки или время',
        'copyright' => 'Нарушение авторских прав',
        'offensive' => 'Оскорбительное содержание',
        'spam' => 'Спам или реклама',
        'other' => 'Другое',
    ];

    protected $fillable = ['recipe_id', 'user_id', 'reason', 'message', 'status'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
