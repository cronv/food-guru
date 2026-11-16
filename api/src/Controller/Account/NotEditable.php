<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Api\ApiError;

/** Рецепт нельзя править в этом статусе — 422 с кодом not_editable. */
final class NotEditable extends ApiError
{
    public function __construct(string $message)
    {
        parent::__construct(422, $message, 'not_editable');
    }
}
