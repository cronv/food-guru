<?php

namespace App\Models;

use App\Enums\EmailTokenType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property EmailTokenType $type
 * @property string $email
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 *
 * @method static Builder<static>|EmailToken newModelQuery()
 * @method static Builder<static>|EmailToken newQuery()
 * @method static Builder<static>|EmailToken query()
 * @method static Builder<static>|EmailToken usable()
 * @method static Builder<static>|EmailToken whereCreatedAt($value)
 * @method static Builder<static>|EmailToken whereEmail($value)
 * @method static Builder<static>|EmailToken whereExpiresAt($value)
 * @method static Builder<static>|EmailToken whereId($value)
 * @method static Builder<static>|EmailToken whereTokenHash($value)
 * @method static Builder<static>|EmailToken whereType($value)
 * @method static Builder<static>|EmailToken whereUpdatedAt($value)
 * @method static Builder<static>|EmailToken whereUsedAt($value)
 * @method static Builder<static>|EmailToken whereUserId($value)
 *
 * @mixin \Eloquent
 */
class EmailToken extends Model
{
    protected $fillable = [
        'user_id',
        'token_hash',
        'type',
        'email',
        'expires_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => EmailTokenType::class,
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Токен годен, если не погашен и не просрочен. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
