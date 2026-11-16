<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Ошибка с машинным кодом: {"message": "...", "code": "not_editable", ...} — фронтенд
 * ветвится по code, а message показывает человеку.
 */
class ApiError extends HttpException
{
    /** @param array<string, mixed> $extra дополнительные поля ответа (balance, shortfall…) */
    public function __construct(int $status, string $message, public readonly string $errorCode, public readonly array $extra = [])
    {
        parent::__construct($status, $message);
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return ['message' => $this->getMessage(), 'code' => $this->errorCode] + $this->extra;
    }
}
