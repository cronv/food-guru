<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Catalog\PublishedRecipes;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Единственное место, где меняется баланс.
 *
 * Каждое движение — проводка в wallet_transactions и изменение wallets.balance
 * в одной транзакции под блокировкой строки кошелька. Два одновременных
 * списания не уведут баланс в минус: второе дождётся первого и увидит остаток.
 */
final class WalletService
{
    public const TOPUP = 'topup';
    public const PURCHASE = 'purchase';
    public const REFUND = 'refund';
    public const ADJUSTMENT = 'adjustment';

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    public function balance(int $userId): int
    {
        return (int) ($this->db->fetchOne('SELECT balance FROM wallets WHERE user_id = ?', [$userId]) ?: 0);
    }

    /**
     * Зачислить. Повторный вызов с тем же платежом ничего не делает.
     *
     * @return int id проводки
     */
    public function credit(int $userId, int $amount, string $type, string $description, ?string $subjectType = null, ?int $subjectId = null, ?string $paymentId = null, ?int $by = null): int
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Сумма зачисления должна быть больше нуля.');
        }

        return $this->move($userId, $amount, $type, $description, $subjectType, $subjectId, $paymentId, $by);
    }

    /**
     * Списать. Не хватает денег — InsufficientFunds, баланс не трогается.
     *
     * @return int id проводки
     */
    public function debit(int $userId, int $amount, string $type, string $description, ?string $subjectType = null, ?int $subjectId = null, ?int $by = null): int
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Сумма списания должна быть больше нуля.');
        }

        return $this->move($userId, -$amount, $type, $description, $subjectType, $subjectId, null, $by);
    }

    private function move(int $userId, int $amount, string $type, string $description, ?string $subjectType, ?int $subjectId, ?string $paymentId, ?int $by): int
    {
        return $this->db->transactional(function () use ($userId, $amount, $type, $description, $subjectType, $subjectId, $paymentId, $by): int {
            $now = PublishedRecipes::now($this->clock->now());
            $this->db->executeStatement('INSERT IGNORE INTO wallets (user_id, balance, created_at, updated_at) VALUES (?, 0, ?, ?)', [$userId, $now, $now]);
            $balance = (int) $this->db->fetchOne('SELECT balance FROM wallets WHERE user_id = ? FOR UPDATE', [$userId]);

            // Под блокировкой кошелька: повторное уведомление о том же платеже не зачислит второй раз.
            if (null !== $paymentId) {
                $done = $this->db->fetchOne('SELECT id FROM wallet_transactions WHERE payment_id = ?', [$paymentId]);
                if (false !== $done) {
                    return (int) $done;
                }
            }

            $after = $balance + $amount;
            if ($after < 0) {
                throw new InsufficientFunds(-$amount, $balance);
            }

            $this->db->executeStatement('UPDATE wallets SET balance = ?, updated_at = ? WHERE user_id = ?', [$after, $now, $userId]);
            $this->db->insert('wallet_transactions', [
                'user_id' => $userId,
                'amount' => $amount,
                'balance_after' => $after,
                'type' => $type,
                'description' => mb_substr($description, 0, 255),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'payment_id' => $paymentId,
                'created_by' => $by,
                'created_at' => $now,
            ]);

            return (int) $this->db->lastInsertId();
        });
    }
}
