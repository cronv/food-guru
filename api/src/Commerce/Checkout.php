<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Api\ApiError;
use App\Api\Validation\ValidationFailed;
use App\Catalog\PublishedRecipes;
use App\Support\ActivityLog;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Покупки с баланса: книга, подписка, заказ в магазине, отмена заказа — как
 * Checkout. Каждая операция — одна транзакция: списание и выдача
 * доступа либо происходят вместе, либо не происходят вовсе.
 */
final class Checkout
{
    public function __construct(
        private readonly Connection $db,
        private readonly WalletService $wallet,
        private readonly ActivityLog $log,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Купить книгу. Уже куплена — второй раз не списываем.
     *
     * @param array<string, mixed> $book
     */
    public function buyBook(int $userId, array $book): void
    {
        if (0 === (int) $book['price']) {
            throw ValidationFailed::field('book', 'Эта книга бесплатная — её не нужно покупать.');
        }

        $this->db->transactional(function () use ($userId, $book): void {
            // Блокировка кошелька внутри debit сериализует покупки одного человека,
            // поэтому повторная проверка здесь видит покупку из соседнего запроса.
            $tx = $this->wallet->debit($userId, (int) $book['price'], WalletService::PURCHASE, "Книга «{$book['title']}»", 'Book', (int) $book['id']);

            $existing = $this->db->fetchOne('SELECT id FROM book_purchases WHERE user_id = ? AND book_id = ? FOR UPDATE', [$userId, $book['id']]);
            if (false !== $existing) {
                // Исключение откатывает списание вместе со всей транзакцией.
                throw new ApiError(409, 'Эта книга уже у вас.', 'already_owned');
            }

            $this->db->insert('book_purchases', [
                'user_id' => $userId, 'book_id' => $book['id'], 'price' => (int) $book['price'],
                'wallet_transaction_id' => $tx, 'created_at' => PublishedRecipes::now($this->clock->now()),
            ]);
            $this->log->record($userId, 'book.purchased', 'Book', (int) $book['id'], ['title' => $book['title'], 'price' => (int) $book['price']]);
        });
    }

    /**
     * Оплатить подписку. Если она уже идёт — новый период начинается с её конца.
     *
     * @param array<string, mixed> $plan
     */
    public function subscribe(int $userId, array $plan): void
    {
        $this->db->transactional(function () use ($userId, $plan): void {
            $tx = $this->wallet->debit($userId, (int) $plan['price'], WalletService::PURCHASE, "Закрытая академия: {$plan['title']}", 'SubscriptionPlan', (int) $plan['id']);

            $utc = new \DateTimeZone('UTC');
            $now = $this->clock->now()->setTimezone($utc);
            $current = $this->db->fetchOne('SELECT MAX(ends_at) FROM subscriptions WHERE user_id = ? AND ends_at > ?', [$userId, PublishedRecipes::now($now)]);
            $start = $current ? new \DateTimeImmutable((string) $current, $utc) : $now;
            $end = self::addMonthsNoOverflow($start, (int) $plan['months']);

            $stamp = PublishedRecipes::now($now);
            $this->db->insert('subscriptions', [
                'user_id' => $userId, 'plan_id' => $plan['id'],
                'starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $end->format('Y-m-d H:i:s'),
                'price' => (int) $plan['price'], 'wallet_transaction_id' => $tx, 'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            $this->log->record($userId, 'subscription.purchased', 'SubscriptionPlan', (int) $plan['id'], ['title' => $plan['title'], 'ends_at' => $end->format('Y-m-d\TH:i:sP')]);
        });
    }

    /**
     * Оформить заказ: проверить остатки, посчитать сумму по ценам из базы
     * (не из запроса), списать деньги, уменьшить остатки, сохранить заказ.
     *
     * @param list<array{product_id: int|string, quantity: int|string}> $lines
     * @param array<string, mixed>                                      $delivery
     *
     * @return int id заказа
     */
    public function placeOrder(int $userId, array $lines, array $delivery): int
    {
        $method = Delivery::METHODS[(string) $delivery['delivery_method']];

        return $this->db->transactional(function () use ($userId, $lines, $delivery, $method): int {
            // Одинаковые товары в корзине складываем, чтобы остаток проверялся по сумме.
            $wanted = [];
            foreach ($lines as $line) {
                $wanted[(int) $line['product_id']] = ($wanted[(int) $line['product_id']] ?? 0) + (int) $line['quantity'];
            }
            // Блокируем строки товаров в одном порядке — без взаимных блокировок.
            ksort($wanted);
            $products = [];
            foreach ($this->db->fetchAllAssociative('SELECT * FROM products WHERE id IN (?) ORDER BY id FOR UPDATE', [array_keys($wanted)], [ArrayParameterType::INTEGER]) as $p) {
                $products[(int) $p['id']] = $p;
            }

            $errors = [];
            foreach ($wanted as $id => $qty) {
                $p = $products[$id] ?? null;
                if (null === $p || !(bool) $p['is_published']) {
                    $errors["items.{$id}"] = ['Товар больше не продаётся.'];
                } elseif ((int) $p['stock'] < $qty) {
                    $errors["items.{$id}"] = [(int) $p['stock'] > 0 ? "«{$p['title']}»: в наличии только {$p['stock']} шт." : "«{$p['title']}» закончился."];
                }
            }
            if ([] !== $errors) {
                throw new ValidationFailed($errors);
            }

            $itemsTotal = 0;
            foreach ($wanted as $id => $qty) {
                $itemsTotal += (int) $products[$id]['price'] * $qty;
            }
            $total = $itemsTotal + $method['price'];
            $now = PublishedRecipes::now($this->clock->now());
            $number = $this->orderNumber();

            $this->db->insert('orders', [
                'number' => $number,
                'user_id' => $userId,
                'status' => Orders::PAID,
                'items_total' => $itemsTotal,
                'delivery_method' => $delivery['delivery_method'],
                'delivery_price' => $method['price'],
                'total' => $total,
                'recipient_name' => $delivery['recipient_name'],
                'phone' => $delivery['phone'],
                'city' => $method['needs_address'] ? $delivery['city'] : null,
                'address' => $method['needs_address'] ? $delivery['address'] : null,
                'comment' => $delivery['comment'] ?? null,
                'paid_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $orderId = (int) $this->db->lastInsertId();

            $tx = $this->wallet->debit($userId, $total, WalletService::PURCHASE, "Заказ №{$number}", 'Order', $orderId);
            $this->db->update('orders', ['wallet_transaction_id' => $tx], ['id' => $orderId]);

            foreach ($wanted as $id => $qty) {
                $p = $products[$id];
                $this->db->insert('order_items', [
                    'order_id' => $orderId, 'product_id' => $id, 'title' => $p['title'],
                    'price' => (int) $p['price'], 'quantity' => $qty, 'total' => (int) $p['price'] * $qty,
                ]);
                // decrement() в Eloquent трогает и updated_at — как и здесь.
                $this->db->executeStatement('UPDATE products SET stock = stock - ?, updated_at = ? WHERE id = ?', [$qty, $now, $id]);
            }

            $this->log->record($userId, 'order.placed', 'Order', $orderId, ['number' => $number, 'total' => $total]);

            return $orderId;
        });
    }

    /** Отменить заказ: вернуть деньги на баланс и товары на склад. */
    public function cancelOrder(int $orderId, int $byUserId): void
    {
        $this->db->transactional(function () use ($orderId, $byUserId): void {
            $order = $this->db->fetchAssociative('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]) ?: throw new \DomainException('Нет такого заказа.');
            if (!Orders::canMoveTo((string) $order['status'], Orders::CANCELED)) {
                throw ValidationFailed::field('status', 'Этот заказ уже нельзя отменить.');
            }

            $now = PublishedRecipes::now($this->clock->now());
            $this->db->update('orders', ['status' => Orders::CANCELED, 'canceled_at' => $now, 'updated_at' => $now], ['id' => $orderId]);

            foreach ($this->db->fetchAllAssociative('SELECT product_id, quantity FROM order_items WHERE order_id = ?', [$orderId]) as $item) {
                if (null !== $item['product_id']) {
                    $this->db->executeStatement('UPDATE products SET stock = stock + ?, updated_at = ? WHERE id = ?', [(int) $item['quantity'], $now, $item['product_id']]);
                }
            }

            $this->wallet->credit((int) $order['user_id'], (int) $order['total'], WalletService::REFUND, "Возврат за заказ №{$order['number']}", 'Order', $orderId, by: $byUserId);
            $this->log->record($byUserId, 'order.canceled', 'Order', $orderId, ['number' => $order['number']]);
        });
    }

    /**
     * Как addMonthsNoOverflow() в Carbon: 31 января + 1 месяц = 28 (29) февраля,
     * а не 3 марта.
     */
    public static function addMonthsNoOverflow(\DateTimeImmutable $date, int $months): \DateTimeImmutable
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n') + $months;
        $year += intdiv($month - 1, 12);
        $month = (($month - 1) % 12) + 1;
        $lastDay = (int) (new \DateTimeImmutable(\sprintf('%04d-%02d-01', $year, $month), $date->getTimezone()))->format('t');

        return $date->setDate($year, $month, min((int) $date->format('j'), $lastDay));
    }

    /** FG-2611-4K7Q: год-месяц и случайный хвост — не выдаёт, сколько всего заказов. */
    private function orderNumber(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        do {
            $tail = '';
            for ($i = 0; $i < 4; ++$i) {
                $tail .= $alphabet[random_int(0, \strlen($alphabet) - 1)];
            }
            $number = 'FG-'.$this->clock->now()->format('ym').'-'.$tail;
        } while (false !== $this->db->fetchOne('SELECT 1 FROM orders WHERE number = ?', [$number]));

        return $number;
    }
}
