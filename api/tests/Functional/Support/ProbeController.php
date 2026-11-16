<?php

declare(strict_types=1);

namespace App\Tests\Functional\Support;

use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Только для тестов (подключается в when@test): закрытый адрес на чтение и
 * на запись — чтобы проверять мост сессий и CSRF без настоящих модулей.
 */
#[AsController]
final class ProbeController
{
    #[Route('/_probe', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(['id' => $user->getId(), 'roles' => $user->getRoles()]);
    }

    #[Route('/_probe/admin', methods: ['GET'])]
    #[IsGranted('ROLE_EDITOR_LEAD')]
    public function lead(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

    #[Route('/_probe/public', methods: ['GET', 'POST'])]
    public function public(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
