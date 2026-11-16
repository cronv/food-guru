<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Catalog\PublishedRecipes;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Кто что может открыть. Все проверки
 * доступа к платному здесь, чтобы правило «подписчик читает книги из подписки»
 * не разъехалось по контроллерам.
 */
final class Access
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    public function hasSubscription(?User $user): bool
    {
        if (null === $user) {
            return false;
        }

        $now = PublishedRecipes::now($this->clock->now());

        return false !== $this->db->fetchOne(
            'SELECT 1 FROM subscriptions WHERE user_id = ? AND starts_at <= ? AND ends_at > ? LIMIT 1',
            [$user->getId(), $now, $now],
        );
    }

    /**
     * До какого момента оплачен доступ, или null, если подписки нет сейчас.
     * Продления идут встык, поэтому достаточно самого позднего конца —
     * но только если какой-то период действует прямо сейчас.
     */
    public function subscriptionEndsAt(?User $user): ?\DateTimeImmutable
    {
        if (!$this->hasSubscription($user)) {
            return null;
        }

        $last = $this->db->fetchOne('SELECT MAX(ends_at) FROM subscriptions WHERE user_id = ?', [$user?->getId()]);

        return $last ? new \DateTimeImmutable((string) $last, new \DateTimeZone('UTC')) : null;
    }

    /** @return array{active: bool, ends_at: string|null} */
    public function subscriptionState(?User $user): array
    {
        $ends = $this->subscriptionEndsAt($user);

        return ['active' => null !== $ends, 'ends_at' => $ends?->format('Y-m-d\TH:i:sP')];
    }

    /** @param array<string, mixed> $book */
    public function owns(?User $user, array $book): bool
    {
        return null !== $user && false !== $this->db->fetchOne(
            'SELECT 1 FROM book_purchases WHERE book_id = ? AND user_id = ? LIMIT 1',
            [$book['id'], $user->getId()],
        );
    }

    /**
     * Читать всю книгу на сайте.
     *
     * @param array<string, mixed> $book
     */
    public function canRead(?User $user, array $book): bool
    {
        return 0 === (int) $book['price']
            || $this->owns($user, $book)
            || ((bool) $book['in_subscription'] && $this->hasSubscription($user));
    }

    /**
     * @param array<string, mixed> $book
     * @param array<string, mixed> $chapter
     */
    public function canReadChapter(?User $user, array $book, array $chapter): bool
    {
        return (bool) $chapter['is_preview'] || $this->canRead($user, $book);
    }

    /**
     * Скачать PDF. Только бесплатные и купленные: книга по подписке после
     * скачивания осталась бы у человека и после конца подписки.
     *
     * @param array<string, mixed> $book
     */
    public function canDownload(?User $user, array $book): bool
    {
        return null !== $user && (0 === (int) $book['price'] || $this->owns($user, $book));
    }
}
