<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Validation\InputValidator;
use App\Api\Validation\ValidationFailed;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** Правила и тексты: фронтенд показывает их людям дословно. */
final class InputValidatorTest extends TestCase
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $rules
     * @param array<string, string> $messages
     *
     * @return array<string, list<string>>
     */
    private function errors(array $query, array $rules, array $messages = []): array
    {
        $validator = new InputValidator($this->createStub(Connection::class));
        try {
            $validator->validate(Request::create('/x', 'GET', $query), $rules, $messages);
        } catch (ValidationFailed $e) {
            return $e->errors;
        }

        return [];
    }

    public function testMessagesAndWildcardOrder(): void
    {
        $errors = $this->errors(
            ['difficulty' => ['x'], 'sort' => 'bad', 'per_page' => '100'],
            ['difficulty' => 'nullable|array', 'difficulty.*' => 'string|in:easy,hard', 'sort' => 'nullable|in:new', 'per_page' => 'nullable|integer|max:48'],
        );

        self::assertSame([
            'sort' => ['The selected sort is invalid.'],
            'per_page' => ['The per page field must not be greater than 48.'],
            'difficulty.0' => ['The selected difficulty.0 is invalid.'],
        ], $errors);
    }

    public function testSizeDependsOnType(): void
    {
        self::assertSame(['q' => ['The q field must not be greater than 3 characters.']], $this->errors(['q' => 'суп!'], ['q' => 'string|max:3']));
        self::assertSame(['tags' => ['The tags field must not have more than 1 items.']], $this->errors(['tags' => ['a', 'b']], ['tags' => 'array|max:1']));
        self::assertSame(['n' => ['The n field must be at least 5.']], $this->errors(['n' => '4'], ['n' => 'integer|min:5']));
    }

    public function testEmptyStringsBecomeNullAndSkipOptionalRules(): void
    {
        self::assertSame([], $this->errors(['q' => '   '], ['q' => 'nullable|string|min:3']));
        self::assertSame(['q' => ['The q field is required.']], $this->errors(['q' => '  '], ['q' => 'required|string']));
    }

    public function testCustomMessagesWin(): void
    {
        self::assertSame(
            ['amount' => ['Минимум — 100 ₽.']],
            $this->errors(['amount' => '5'], ['amount' => 'required|integer|min:100'], ['amount.min' => 'Минимум — :min ₽.']),
        );
    }

    public function testSummaryMessageCountsTheRest(): void
    {
        $e = new ValidationFailed(['a' => ['A.'], 'b' => ['B.'], 'c' => ['C.']]);
        self::assertSame('A. (and 2 more errors)', $e->getMessage());
        self::assertSame('A. (and 1 more error)', (new ValidationFailed(['a' => ['A.'], 'b' => ['B.']]))->getMessage());
    }
}
