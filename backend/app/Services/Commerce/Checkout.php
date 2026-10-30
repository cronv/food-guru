<?php

namespace App\Services\Commerce;

use App\Models\ActivityLog;
use App\Models\Book;
use App\Models\BookPurchase;
use App\Models\Order;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Покупки с баланса: книга, подписка, заказ в магазине, отмена заказа.
 * Каждая операция — одна транзакция: списание и выдача доступа либо
 * происходят вместе, либо не происходят вовсе.
 */
class Checkout
{
    public function __construct(private WalletService $wallet) {}

    /** Купить книгу. Уже куплена — второй раз не списываем. */
    public function buyBook(User $user, Book $book): BookPurchase
    {
        if ($book->isFree()) {
            throw ValidationException::withMessages(['book' => 'Эта книга бесплатная — её не нужно покупать.']);
        }

        return DB::transaction(function () use ($user, $book) {
            // Блокировка кошелька внутри debit сериализует покупки одного человека,
            // поэтому повторная проверка здесь видит покупку из соседнего запроса.
            $tx = $this->wallet->debit($user, $book->price, WalletTransaction::PURCHASE, "Книга «{$book->title}»", $book);

            $existing = BookPurchase::where('user_id', $user->id)->where('book_id', $book->id)->lockForUpdate()->first();
            if ($existing) {
                // Откатываем списание всей транзакцией.
                throw new AlreadyOwned($existing);
            }

            $purchase = BookPurchase::create([
                'user_id' => $user->id,
                'book_id' => $book->id,
                'price' => $book->price,
                'wallet_transaction_id' => $tx->id,
            ]);

            ActivityLog::record($user, 'book.purchased', $book, ['title' => $book->title, 'price' => $book->price]);

            return $purchase;
        });
    }

    /** Оплатить подписку. Если она уже идёт — новый период начинается с её конца. */
    public function subscribe(User $user, SubscriptionPlan $plan): Subscription
    {
        return DB::transaction(function () use ($user, $plan) {
            $tx = $this->wallet->debit($user, $plan->price, WalletTransaction::PURCHASE, "Закрытая академия: {$plan->title}", $plan);

            $current = Subscription::where('user_id', $user->id)->where('ends_at', '>', now())->max('ends_at');
            $start = $current ? Carbon::parse($current) : now();

            $sub = Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMonthsNoOverflow($plan->months),
                'price' => $plan->price,
                'wallet_transaction_id' => $tx->id,
            ]);

            ActivityLog::record($user, 'subscription.purchased', $plan, ['title' => $plan->title, 'ends_at' => $sub->ends_at->toIso8601String()]);

            return $sub;
        });
    }

    /**
     * Оформить заказ: проверить остатки, посчитать сумму по ценам из базы
     * (не из запроса), списать деньги, уменьшить остатки, сохранить заказ.
     *
     * @param  array<int, array{product_id:int, quantity:int}>  $lines
     */
    public function placeOrder(User $user, array $lines, array $delivery): Order
    {
        $method = Order::DELIVERY[$delivery['delivery_method']];

        return DB::transaction(function () use ($user, $lines, $delivery, $method) {
            // Одинаковые товары в корзине складываем, чтобы остаток проверялся по сумме.
            $wanted = [];
            foreach ($lines as $line) {
                $wanted[$line['product_id']] = ($wanted[$line['product_id']] ?? 0) + $line['quantity'];
            }

            // Блокируем строки товаров в одном порядке — без взаимных блокировок.
            ksort($wanted);
            $products = Product::whereIn('id', array_keys($wanted))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $errors = [];
            foreach ($wanted as $id => $qty) {
                $p = $products->get($id);
                if (! $p || ! $p->is_published) {
                    $errors["items.{$id}"] = 'Товар больше не продаётся.';
                } elseif ($p->stock < $qty) {
                    $errors["items.{$id}"] = $p->stock > 0
                        ? "«{$p->title}»: в наличии только {$p->stock} шт."
                        : "«{$p->title}» закончился.";
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            $itemsTotal = 0;
            foreach ($wanted as $id => $qty) {
                $itemsTotal += $products[$id]->price * $qty;
            }
            $total = $itemsTotal + $method['price'];

            $order = Order::create([
                'number' => $this->orderNumber(),
                'user_id' => $user->id,
                'status' => Order::PAID,
                'items_total' => $itemsTotal,
                'delivery_method' => $delivery['delivery_method'],
                'delivery_price' => $method['price'],
                'total' => $total,
                'recipient_name' => $delivery['recipient_name'],
                'phone' => $delivery['phone'],
                'city' => $method['needs_address'] ? $delivery['city'] : null,
                'address' => $method['needs_address'] ? $delivery['address'] : null,
                'comment' => $delivery['comment'] ?? null,
                'paid_at' => now(),
            ]);

            $tx = $this->wallet->debit($user, $total, WalletTransaction::PURCHASE, "Заказ №{$order->number}", $order);
            $order->forceFill(['wallet_transaction_id' => $tx->id])->save();

            foreach ($wanted as $id => $qty) {
                $p = $products[$id];
                $order->items()->create([
                    'product_id' => $p->id,
                    'title' => $p->title,
                    'price' => $p->price,
                    'quantity' => $qty,
                    'total' => $p->price * $qty,
                ]);
                $p->decrement('stock', $qty);
            }

            ActivityLog::record($user, 'order.placed', $order, ['number' => $order->number, 'total' => $total]);

            return $order->load('items');
        });
    }

    /** Отменить заказ: вернуть деньги на баланс и товары на склад. */
    public function cancelOrder(Order $order, User $by): Order
    {
        return DB::transaction(function () use ($order, $by) {
            $order = Order::whereKey($order->id)->lockForUpdate()->first();

            if (! $order->canMoveTo(Order::CANCELED)) {
                throw ValidationException::withMessages(['status' => 'Этот заказ уже нельзя отменить.']);
            }

            $order->forceFill(['status' => Order::CANCELED, 'canceled_at' => now()])->save();

            foreach ($order->items as $item) {
                if ($item->product_id) {
                    Product::whereKey($item->product_id)->increment('stock', $item->quantity);
                }
            }

            $this->wallet->credit($order->user, $order->total, WalletTransaction::REFUND, "Возврат за заказ №{$order->number}", $order, by: $by);

            ActivityLog::record($by, 'order.canceled', $order, ['number' => $order->number]);

            return $order->load('items');
        });
    }

    /** FG-2611-4K7Q: год-месяц и случайный хвост — не выдаёт, сколько всего заказов. */
    private function orderNumber(): string
    {
        do {
            $number = 'FG-'.now()->format('ym').'-'.Str::upper(Str::random(4));
        } while (Order::where('number', $number)->exists());

        return $number;
    }
}
