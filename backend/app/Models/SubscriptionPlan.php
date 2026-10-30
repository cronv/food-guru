<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $title
 * @property int $months
 * @property int $price
 * @property bool $is_active
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubscriptionPlan newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubscriptionPlan newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubscriptionPlan query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubscriptionPlan whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubscriptionPlan whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubscriptionPlan whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubscriptionPlan whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubscriptionPlan whereMonths($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubscriptionPlan wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubscriptionPlan wherePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubscriptionPlan whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SubscriptionPlan whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class SubscriptionPlan extends Model
{
    protected $fillable = ['code', 'title', 'months', 'price', 'is_active', 'position'];

    protected function casts(): array
    {
        return ['months' => 'integer', 'price' => 'integer', 'is_active' => 'boolean'];
    }

    public function toApi(): array
    {
        return [
            'code' => $this->code,
            'title' => $this->title,
            'months' => $this->months,
            'price' => $this->price,
            // Цена месяца — чтобы показать выгоду годового плана. До рубля: «658,33 ₽» читается хуже.
            'per_month' => (int) round($this->price / max(1, $this->months) / 100) * 100,
        ];
    }
}
