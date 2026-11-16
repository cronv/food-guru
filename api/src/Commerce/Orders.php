<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Catalog\RecipePresenter;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** Заказы магазина: статусы, переходы и вид в ответе. */
final class Orders
{
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

    public const TRANSITIONS = [
        self::PAID => [self::ASSEMBLING, self::CANCELED],
        self::ASSEMBLING => [self::SHIPPED, self::CANCELED],
        self::SHIPPED => [self::DELIVERED],
        self::DELIVERED => [],
        self::CANCELED => [],
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    public static function canMoveTo(string $from, string $to): bool
    {
        return \in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Order::toApi() с позициями.
     *
     * @param list<array<string, mixed>> $orders строки orders
     *
     * @return list<array<string, mixed>>
     */
    public function present(array $orders): array
    {
        if ([] === $orders) {
            return [];
        }

        $items = [];
        foreach ($this->db->fetchAllAssociative('SELECT * FROM order_items WHERE order_id IN (?) ORDER BY id', [array_map(static fn (array $o): int => (int) $o['id'], $orders)], [ArrayParameterType::INTEGER]) as $i) {
            $items[(int) $i['order_id']][] = [
                'product_id' => null === $i['product_id'] ? null : (int) $i['product_id'],
                'title' => $i['title'],
                'price' => (int) $i['price'],
                'quantity' => (int) $i['quantity'],
                'total' => (int) $i['total'],
            ];
        }

        return array_map(static fn (array $o): array => [
            'number' => $o['number'],
            'status' => $o['status'],
            'status_label' => self::STATUSES[$o['status']] ?? $o['status'],
            // Покупатель сам отменяет, пока заказ не начали собирать.
            'can_cancel' => self::PAID === $o['status'],
            'next_statuses' => self::TRANSITIONS[$o['status']] ?? [],
            'items_total' => (int) $o['items_total'],
            'delivery_method' => $o['delivery_method'],
            'delivery_label' => Delivery::METHODS[$o['delivery_method']]['label'] ?? $o['delivery_method'],
            'delivery_price' => (int) $o['delivery_price'],
            'total' => (int) $o['total'],
            'recipient_name' => $o['recipient_name'],
            'phone' => $o['phone'],
            'city' => $o['city'],
            'address' => $o['address'],
            'comment' => $o['comment'],
            'items' => $items[(int) $o['id']] ?? [],
            'created_at' => RecipePresenter::iso($o['created_at']),
            'shipped_at' => RecipePresenter::iso($o['shipped_at']),
            'delivered_at' => RecipePresenter::iso($o['delivered_at']),
            'canceled_at' => RecipePresenter::iso($o['canceled_at']),
        ], $orders);
    }
}
