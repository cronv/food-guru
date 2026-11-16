<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Api\Validation\InputValidator;
use App\Entity\User;
use App\Enum\UserRole;
use App\Security\Attribute\VerifiedEmail;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CONTENT_MANAGER'), VerifiedEmail]
final class AdminWalletController
{
    public function __construct(
        private readonly Connection $db,
        private readonly InputValidator $validator,
    ) {
    }

    #[Route('/admin/users/{userId}/wallet', name: 'admin_wallet_show', requirements: ['userId' => '\d+'], methods: ['GET'])]
    public function show(int $userId, #[CurrentUser] User $actor): JsonResponse
    {
        if (UserRole::Admin !== $actor->getRole()) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        // ensure wallet row
        $this->db->executeStatement('INSERT IGNORE INTO wallets (user_id, balance, created_at, updated_at) VALUES (?, 0, ?, ?)', [$userId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        $balance = (int) ($this->db->fetchOne('SELECT balance FROM wallets WHERE user_id = ?', [$userId]) ?: 0);

        $txs = $this->db->fetchAllAssociative(
            'SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 50',
            [$userId]
        );

        return new JsonResponse(['data' => [
            'balance' => $balance,
            'transactions' => $txs,
        ]]);
    }

    #[Route('/admin/users/{userId}/wallet', name: 'admin_wallet_adjust', requirements: ['userId' => '\d+'], methods: ['POST'])]
    public function adjust(int $userId, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        if (UserRole::Admin !== $actor->getRole()) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        $data = $this->validator->validate($request, [
            'amount' => 'required|integer',
            'reason' => 'required|string|min:5|max:200',
        ]);

        $amount = (int) $data['amount'];
        if (0 === $amount || $amount < -10000000 || $amount > 10000000) {
            return new JsonResponse(['message' => 'Недопустимая сумма.'], 422);
        }

        $this->db->beginTransaction();
        try {
            $this->db->executeStatement('INSERT IGNORE INTO wallets (user_id, balance, created_at, updated_at) VALUES (?, 0, ?, ?)', [$userId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            $wallet = $this->db->fetchAssociative('SELECT * FROM wallets WHERE user_id = ? FOR UPDATE', [$userId]);
            if (!$wallet) {
                throw new \RuntimeException('Wallet not found.');
            }
            $balance = (int) $wallet['balance'];
            $after = $balance + $amount;
            if ($after < 0) {
                $this->db->rollBack();

                return new JsonResponse(['message' => 'Недостаточно средств.'], 422);
            }
            $this->db->update('wallets', ['balance' => $after, 'updated_at' => date('Y-m-d H:i:s')], ['user_id' => $userId]);
            $this->db->insert('wallet_transactions', [
                'user_id' => $userId,
                'amount' => $amount,
                'balance_after' => $after,
                'type' => 'adjustment',
                'description' => mb_substr((string) $data['reason'], 0, 255),
                'created_by' => $actor->getId(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $txId = (int) $this->db->lastInsertId();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $tx = $this->db->fetchAssociative('SELECT * FROM wallet_transactions WHERE id = ?', [$txId]);

        $this->db->insert('activity_log', [
            'actor_id' => $actor->getId(),
            'action' => 'wallet.adjusted',
            'subject_type' => 'User',
            'subject_id' => $userId,
            'payload' => json_encode(['email' => '', 'amount' => $amount, 'reason' => $data['reason']]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return new JsonResponse(['data' => ['balance' => $after, 'transaction' => $tx]]);
    }
}
