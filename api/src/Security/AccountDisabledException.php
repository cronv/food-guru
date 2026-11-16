<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * Аккаунт отключён в админке. Наследник CustomUserMessage…: обычные
 * AccountStatusException Symfony прячет за «неверными данными»
 * (hide_user_not_found), а фронтенду нужен именно ответ account_disabled.
 */
final class AccountDisabledException extends CustomUserMessageAccountStatusException
{
    public function __construct()
    {
        parent::__construct('Account is disabled.');
    }
}
