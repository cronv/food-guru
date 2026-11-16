<?php

declare(strict_types=1);

namespace App\Support;

use App\Catalog\PublishedRecipes;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/** Журнал действий (таблица activity_log), запись через ActivityLog::record(). */
final class ActivityLog
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param string               $subjectType короткое имя модели без пространства имён: Recipe, Review
     * @param array<string, mixed> $payload
     */
    public function record(?int $actorId, string $action, ?string $subjectType = null, ?int $subjectId = null, array $payload = []): void
    {
        $this->db->insert('activity_log', [
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload' => [] === $payload ? null : json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
            'created_at' => PublishedRecipes::now($this->clock->now()),
        ]);
    }
}
