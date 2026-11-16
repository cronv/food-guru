<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Материал с публикацией: выпуск журнала, конкурс, занятие академии, рецепт.
 * У всех одинаковое правило «виден всем»: статус published и дата публикации
 * наступила. Проверку доступа делает PublishableVoter.
 */
final readonly class Publishable
{
    public function __construct(
        public string $status,
        public ?\DateTimeImmutable $publishedAt,
        public ?int $authorId,
    ) {
    }

    /** @param array<string, mixed> $row строка таблицы с колонками status, published_at, author_id */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['status'],
            null === $row['published_at'] ? null : new \DateTimeImmutable((string) $row['published_at'], new \DateTimeZone('UTC')),
            null === ($row['author_id'] ?? null) ? null : (int) $row['author_id'],
        );
    }

    public function isPublic(\DateTimeImmutable $now): bool
    {
        return 'published' === $this->status && null !== $this->publishedAt && $this->publishedAt < $now;
    }
}
