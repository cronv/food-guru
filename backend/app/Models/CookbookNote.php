<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Своя запись в рецептосохранялке: рецепт по ссылке или записанный от руки.
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string|null $url
 * @property string|null $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CookbookNote newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CookbookNote newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CookbookNote query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CookbookNote whereBody($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CookbookNote whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CookbookNote whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CookbookNote whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CookbookNote whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CookbookNote whereUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CookbookNote whereUserId($value)
 *
 * @mixin \Eloquent
 */
class CookbookNote extends Model
{
    protected $fillable = ['title', 'url', 'body'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url,
            'body' => $this->body,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
