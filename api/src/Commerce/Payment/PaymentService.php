<?php

declare(strict_types=1);

namespace App\Commerce\Payment;

use App\Catalog\PublishedRecipes;
use App\Catalog\RecipePresenter;
use App\Commerce\WalletService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Пополнение кошелька: создать платёж, подтвердить, отменить. */
final class PaymentService
{
    public const PENDING = 'pending';
    public const SUCCEEDED = 'succeeded';
    public const CANCELED = 'canceled';

    public const METHODS = [
        'card' => 'Банковская карта',
        'sbp' => 'СБП',
        'sberpay' => 'SberPay',
        'yoomoney' => 'ЮMoney',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly WalletService $wallet,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return array<string, mixed> строка payments */
    public function createTopUp(PaymentGateway $gateway, int $userId, int $amount, string $method): array
    {
        // UUID v7: упорядочен по времени и не выдаёт число платежей.
        $id = Uuid::v7()->toRfc4122();
        $now = PublishedRecipes::now($this->clock->now());
        $this->db->insert('payments', [
            'id' => $id, 'user_id' => $userId, 'amount' => $amount, 'method' => $method,
            'provider' => $gateway->code(), 'status' => self::PENDING, 'created_at' => $now, 'updated_at' => $now,
        ]);

        [$providerId, $url] = $gateway->create($id, $amount);
        $this->db->update('payments', ['provider_payment_id' => $providerId, 'confirmation_url' => $url, 'updated_at' => $now], ['id' => $id]);

        return $this->find($id) ?? throw new \LogicException('Платёж не сохранился.');
    }

    /**
     * Платёж прошёл: зачислить деньги. Безопасно вызывать повторно — провайдеры
     * присылают уведомление несколько раз, а зачисление должно быть одно.
     *
     * @return array<string, mixed>
     */
    public function succeed(string $id): array
    {
        return $this->db->transactional(function () use ($id): array {
            $payment = $this->db->fetchAssociative('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$id]) ?: throw new \DomainException('Нет такого платежа.');
            if (self::SUCCEEDED === $payment['status']) {
                return $payment;
            }
            if (self::PENDING !== $payment['status']) {
                throw new \DomainException('Платёж уже отменён.');
            }

            $now = PublishedRecipes::now($this->clock->now());
            $this->db->update('payments', ['status' => self::SUCCEEDED, 'paid_at' => $now, 'updated_at' => $now], ['id' => $id]);
            $this->wallet->credit(
                (int) $payment['user_id'],
                (int) $payment['amount'],
                WalletService::TOPUP,
                'Пополнение: '.(self::METHODS[$payment['method']] ?? $payment['method']),
                paymentId: $id,
            );

            return $this->find($id) ?? $payment;
        });
    }

    /** @return array<string, mixed> */
    public function cancel(string $id): array
    {
        return $this->db->transactional(function () use ($id): array {
            $payment = $this->db->fetchAssociative('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$id]) ?: throw new \DomainException('Нет такого платежа.');
            if (self::PENDING === $payment['status']) {
                $now = PublishedRecipes::now($this->clock->now());
                $this->db->update('payments', ['status' => self::CANCELED, 'canceled_at' => $now, 'updated_at' => $now], ['id' => $id]);
            }

            return $this->find($id) ?? $payment;
        });
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM payments WHERE id = ?', [$id]);

        return false === $row ? null : $row;
    }

    /**
     * Payment::toApi().
     *
     * @param array<string, mixed> $p
     *
     * @return array<string, mixed>
     */
    public static function present(array $p): array
    {
        return [
            'id' => $p['id'],
            'amount' => (int) $p['amount'],
            'method' => $p['method'],
            'method_label' => self::METHODS[$p['method']] ?? $p['method'],
            'status' => $p['status'],
            'provider' => $p['provider'],
            'confirmation_url' => self::PENDING === $p['status'] ? $p['confirmation_url'] : null,
            'paid_at' => RecipePresenter::iso($p['paid_at']),
            'created_at' => RecipePresenter::iso($p['created_at']),
        ];
    }
}
