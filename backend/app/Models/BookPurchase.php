<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $book_id
 * @property int $price
 * @property int|null $wallet_transaction_id
 * @property Carbon|null $created_at
 * @property-read Book $book
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookPurchase newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookPurchase newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookPurchase query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookPurchase whereBookId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookPurchase whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookPurchase whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookPurchase wherePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookPurchase whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookPurchase whereWalletTransactionId($value)
 *
 * @mixin \Eloquent
 */
class BookPurchase extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
