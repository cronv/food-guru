<?php

declare(strict_types=1);

namespace App\Content;

use App\Catalog\RecipePresenter;
use App\Entity\User;
use App\Enum\AcademyFormat;
use App\Enum\AcademyLevel;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Занятие академии в JSON: карточка и полная версия.
 * Ссылки на эфир и запись — только тем, кто может смотреть.
 */
final class AcademyPresenter
{
    public function __construct(
        private readonly Connection $db,
        private readonly RecipePresenter $presenter,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Карточки для списков. Ссылок на эфир и запись здесь нет и быть не должно.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public function cards(array $rows): array
    {
        $covers = $this->presenter->mediaByIds(array_column($rows, 'cover_media_id'));

        return array_map(fn (array $s): array => $this->card($s, $covers), $rows);
    }

    /**
     * Полная карточка с состоянием посетителя (AcademyResource + viewerState).
     *
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    public function full(array $s, ?User $user): array
    {
        $registration = null === $user ? false : $this->db->fetchAssociative(
            'SELECT created_at FROM academy_registrations WHERE academy_session_id = ? AND user_id = ? LIMIT 1',
            [$s['id'], $user->getId()],
        );
        $registered = false !== $registration;
        $staff = $user?->isStaff() ?? false;
        // AcademyPolicy::watch: редакции — всегда, остальным — с подтверждённой почтой и записью.
        $canWatch = null !== $user && ($staff || ($user->isVerified() && $registered));
        $author = $this->db->fetchAssociative('SELECT id, name FROM users WHERE id = ?', [$s['author_id']]);

        $out = $this->card($s, $this->presenter->mediaByIds([$s['cover_media_id']]));
        $out['description'] = $s['description'];
        // Пункты «чему научимся» хранятся строками, по одной в строке. Флаг u обязателен:
        // без него \R ловит байт 0x85 внутри кириллицы («х» = D1 85) и режет букву пополам.
        $out['takeaways'] = array_values(array_filter(
            array_map('trim', preg_split('/\R/u', (string) $s['takeaways']) ?: []),
            static fn (string $line): bool => '' !== $line,
        ));
        if ($staff) {
            $out['moderation_note'] = $s['moderation_note'];
        }
        $out['author'] = false === $author ? null : ['id' => (int) $author['id'], 'name' => $author['name']];

        /*
         * Ссылка на эфир — пока эфир идёт: до начала она посетителю бесполезна,
         * а утечь успеет. Редакции обе ссылки видны всегда: иначе редактор открыл
         * бы форму и при сохранении затёр бы то, что уже записано.
         */
        if ($staff || ($canWatch && $this->isLiveNow($s))) {
            $out['stream_url'] = $s['stream_url'];
        }
        if ($staff || $canWatch) {
            $out['record_url'] = $s['record_url'];
        }

        $out['viewer'] = [
            'registered' => $registered,
            'can_watch' => $canWatch,
            'registered_at' => $registered ? RecipePresenter::iso($registration['created_at']) : null,
        ];

        return $out;
    }

    /** @param array<string, mixed> $s */
    public function isUpcoming(array $s): bool
    {
        return AcademyFormat::from((string) $s['format'])->isLive()
            && null !== $s['starts_at']
            && new \DateTimeImmutable((string) $s['starts_at'], new \DateTimeZone('UTC')) > $this->clock->now();
    }

    /**
     * @param array<string, mixed>             $s
     * @param array<int, array<string, mixed>> $covers
     *
     * @return array<string, mixed>
     */
    private function card(array $s, array $covers): array
    {
        $format = AcademyFormat::from((string) $s['format']);
        $level = AcademyLevel::from((string) $s['level']);

        return [
            'id' => (int) $s['id'],
            'slug' => $s['slug'],
            'title' => $s['title'],
            'lead' => $s['lead'],
            'format' => $format->value,
            'format_label' => $format->label(),
            'level' => $level->value,
            'level_label' => $level->label(),
            'status' => $s['status'],
            'host_name' => $s['host_name'],
            'host_title' => $s['host_title'],
            'starts_at' => RecipePresenter::iso($s['starts_at']),
            'ends_at' => $this->endsAt($s)?->format('Y-m-d\TH:i:sP'),
            'duration_minutes' => null === $s['duration_minutes'] ? null : (int) $s['duration_minutes'],
            'is_live_now' => $this->isLiveNow($s),
            'is_upcoming' => $this->isUpcoming($s),
            'has_record' => ($s['record_url'] ?? '') !== '',
            'registrations_count' => (int) $s['registrations_count'],
            'published_at' => RecipePresenter::iso($s['published_at']),
            'cover' => $covers[(int) $s['cover_media_id']] ?? null,
        ];
    }

    /** @param array<string, mixed> $s */
    private function endsAt(array $s): ?\DateTimeImmutable
    {
        if (null === $s['starts_at']) {
            return null;
        }

        $start = new \DateTimeImmutable((string) $s['starts_at'], new \DateTimeZone('UTC'));

        return $start->modify('+'.((int) ($s['duration_minutes'] ?? 60)).' minutes');
    }

    /** @param array<string, mixed> $s */
    private function isLiveNow(array $s): bool
    {
        if (!AcademyFormat::from((string) $s['format'])->isLive() || null === $s['starts_at']) {
            return false;
        }

        $now = $this->clock->now();

        return new \DateTimeImmutable((string) $s['starts_at'], new \DateTimeZone('UTC')) < $now && $this->endsAt($s) > $now;
    }
}
