<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class MeController
{
    public function __construct(private readonly Connection $db)
    {
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        $row = $this->db->fetchAssociative('SELECT * FROM users WHERE id = ?', [$user->getId()]);
        if (!$row) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        return new JsonResponse(['data' => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'role' => $row['role'],
            'email_verified_at' => $row['email_verified_at'],
            'is_active' => (bool) $row['is_active'],
        ]]);
    }
}
