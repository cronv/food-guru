<?php

declare(strict_types=1);

namespace App\Security\Attribute;

/**
 * Действие только с подтверждённой почтой.
 * Иначе оценки, отзывы и голоса легко накрутить с одноразовых адресов.
 * Сам вход проверяет #[IsGranted('ROLE_USER')] — гость получает 401 раньше.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class VerifiedEmail
{
}
