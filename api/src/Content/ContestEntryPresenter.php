<?php

declare(strict_types=1);

namespace App\Content;

use App\Catalog\RecipePresenter;
use App\Entity\User;
use App\Enum\EntryStatus;
use App\Enum\RatingLevel;
use App\Media\MediaUrls;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** Работа на конкурс в JSON. */
final class ContestEntryPresenter
{
    public function __construct(
        private readonly Connection $db,
        private readonly MediaUrls $media,
    ) {
    }

    /**
     * Работы с фото; автор и конкурс — только если «загружены» (whenLoaded).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public function present(array $rows, ?User $viewer, bool $withAuthor, bool $withContest = false): array
    {
        if ([] === $rows) {
            return [];
        }

        $ids = array_map(static fn (array $e): int => (int) $e['id'], $rows);
        $photos = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT m.*, cem.contest_entry_id AS pivot_entry_id FROM media m INNER JOIN contest_entry_media cem ON m.id = cem.media_id
              WHERE cem.contest_entry_id IN (?) ORDER BY cem.position ASC',
            [$ids],
            [ArrayParameterType::INTEGER],
        ) as $m) {
            $photos[(int) $m['pivot_entry_id']][] = $this->media->present($m);
        }

        $authors = $withAuthor ? $this->byIds('users', array_column($rows, 'user_id'), 'id, name, rating_level') : [];
        $contests = $withContest ? $this->byIds('contests', array_column($rows, 'contest_id'), 'id, slug, title') : [];
        $staff = $viewer?->isStaff() ?? false;

        return array_map(static function (array $e) use ($viewer, $staff, $withAuthor, $withContest, $authors, $contests, $photos): array {
            $status = EntryStatus::from((string) $e['status']);
            $out = [
                'id' => (int) $e['id'],
                'title' => $e['title'],
                'body' => $e['body'],
                'status' => $status->value,
                'status_label' => $status->label(),
                'votes_count' => (int) $e['votes_count'],
                'place' => null === $e['place'] ? null : (int) $e['place'],
                'created_at' => RecipePresenter::iso($e['created_at']),
            ];
            // Причину отклонения видит только сам автор и редакция.
            if ($staff || (null !== $viewer && $viewer->getId() === (int) $e['user_id'])) {
                $out['reject_reason'] = $e['reject_reason'];
            }
            if ($withAuthor) {
                $a = $authors[(int) $e['user_id']];
                $level = RatingLevel::from((string) $a['rating_level']);
                $out['author'] = ['id' => (int) $a['id'], 'name' => $a['name'], 'rating_level' => $level->value, 'rating_label' => $level->label()];
            }
            $out['media'] = $photos[(int) $e['id']] ?? [];
            if ($withContest) {
                $c = $contests[(int) $e['contest_id']];
                $out['contest'] = ['slug' => $c['slug'], 'title' => $c['title']];
            }

            return $out;
        }, $rows);
    }

    /**
     * @param list<mixed> $ids
     *
     * @return array<int, array<string, mixed>>
     */
    private function byIds(string $table, array $ids, string $columns): array
    {
        $out = [];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        foreach ($this->db->fetchAllAssociative("SELECT {$columns} FROM {$table} WHERE id IN (?)", [$ids], [ArrayParameterType::INTEGER]) as $row) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
    }
}
