<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** Отключённый в админке аккаунт не может пользоваться даже уже открытой сессией. */
final class ActiveUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && !$user->isActive()) {
            throw new AccountDisabledException();
        }
    }

    public function checkPostAuth(UserInterface $user, mixed $token = null): void
    {
    }
}
