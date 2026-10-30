<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Проводка по кошельку. Не меняется и не удаляется: исправление — новой проводкой.
 *
 * @property int $id
 * @property int $user_id
 * @property int $amount
 * @property int $balance_after
 * @property string $type
 * @property string $description
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $payment_id
 * @property int|null $created_by
 * @property Carbon|null $created_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction whereBalanceAfter($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction wherePaymentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction whereSubjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction whereSubjectType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WalletTransaction whereUserId($value)
 *
 * @mixin \Eloquent
 */
class WalletTransaction extends Model
{
    public const TOPUP = 'topup';

    public const PURCHASE = 'purchase';

    public const REFUND = 'refund';

    public const ADJUSTMENT = 'adjustment';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'balance_after' => 'integer'];
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'balance_after' => $this->balance_after,
            'type' => $this->type,
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
