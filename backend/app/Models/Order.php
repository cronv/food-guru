<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property int $user_id
 * @property string $status
 * @property int $items_total
 * @property string $delivery_method
 * @property int $delivery_price
 * @property int $total
 * @property string $recipient_name
 * @property string $phone
 * @property string|null $city
 * @property string|null $address
 * @property string|null $comment
 * @property int|null $wallet_transaction_id
 * @property Carbon|null $paid_at
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $canceled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, OrderItem> $items
 * @property-read int|null $items_count
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCanceledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereDeliveredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereDeliveryMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereDeliveryPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereItemsTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order wherePaidAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereRecipientName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereShippedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereWalletTransactionId($value)
 *
 * @mixin \Eloquent
 */
class Order extends Model
{
    /** Заказ создаётся уже оплаченным: деньги списываются с баланса в момент оформления. */
    public const PAID = 'paid';

    public const ASSEMBLING = 'assembling';

    public const SHIPPED = 'shipped';

    public const DELIVERED = 'delivered';

    public const CANCELED = 'canceled';

    public const STATUSES = [
        self::PAID => 'Оплачен',
        self::ASSEMBLING => 'Собирается',
        self::SHIPPED => 'Отправлен',
        self::DELIVERED => 'Доставлен',
        self::CANCELED => 'Отменён',
    ];

    /** Куда можно перевести заказ из каждого статуса. Назад — нельзя. */
    public const TRANSITIONS = [
        self::PAID => [self::ASSEMBLING, self::CANCELED],
        self::ASSEMBLING => [self::SHIPPED, self::CANCELED],
        self::SHIPPED => [self::DELIVERED],
        self::DELIVERED => [],
        self::CANCELED => [],
    ];

    /** Способы доставки и цена в копейках. */
    public const DELIVERY = [
        'pickup' => ['label' => 'Самовывоз', 'price' => 0, 'needs_address' => false],
        'courier' => ['label' => 'Курьер по городу', 'price' => 39000, 'needs_address' => true],
        'post' => ['label' => 'Почта России', 'price' => 29000, 'needs_address' => true],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'items_total' => 'integer',
            'delivery_price' => 'integer',
            'total' => 'integer',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function toApi(bool $withUser = false): array
    {
        return [
            'number' => $this->number,
            'status' => $this->status,
            'status_label' => self::STATUSES[$this->status] ?? $this->status,
            // Покупатель сам отменяет, пока заказ не начали собирать.
            'can_cancel' => $this->status === self::PAID,
            'next_statuses' => self::TRANSITIONS[$this->status] ?? [],
            'items_total' => $this->items_total,
            'delivery_method' => $this->delivery_method,
            'delivery_label' => self::DELIVERY[$this->delivery_method]['label'] ?? $this->delivery_method,
            'delivery_price' => $this->delivery_price,
            'total' => $this->total,
            'recipient_name' => $this->recipient_name,
            'phone' => $this->phone,
            'city' => $this->city,
            'address' => $this->address,
            'comment' => $this->comment,
            'items' => $this->items->map(fn (OrderItem $i) => [
                'product_id' => $i->product_id,
                'title' => $i->title,
                'price' => $i->price,
                'quantity' => $i->quantity,
                'total' => $i->total,
            ])->all(),
            'created_at' => $this->created_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'canceled_at' => $this->canceled_at?->toIso8601String(),
        ] + ($withUser ? [
            // Админке нужен числовой id для PATCH; покупателю хватает номера.
            'id' => $this->id,
            'user' => ['id' => $this->user->id, 'name' => $this->user->name, 'email' => $this->user->email],
        ] : []);
    }
}
