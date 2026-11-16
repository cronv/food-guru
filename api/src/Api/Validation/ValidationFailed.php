<?php

declare(strict_types=1);

namespace App\Api\Validation;

/** Ошибки ввода. ApiExceptionSubscriber отдаёт их ответом 422 в формате API. */
final class ValidationFailed extends \RuntimeException
{
    /** @param array<string, list<string>> $errors поле => сообщения */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(self::summarize($errors));
    }

    /** Одно поле — одно сообщение: для ошибок, которые проверяются вручную. */
    public static function field(string $field, string $message): self
    {
        return new self([$field => [$message]]);
    }

    /**
     * Сводка ошибок для текста исключения:
     * «первое сообщение (and 2 more errors)».
     *
     * @param array<string, list<string>> $errors
     */
    private static function summarize(array $errors): string
    {
        $messages = array_merge(...array_values($errors));
        $first = $messages[0] ?? 'The given data was invalid.';
        $more = \count($messages) - 1;

        return $more > 0 ? \sprintf('%s (and %d more %s)', $first, $more, 1 === $more ? 'error' : 'errors') : $first;
    }
}
