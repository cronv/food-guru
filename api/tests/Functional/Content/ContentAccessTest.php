<?php

declare(strict_types=1);

namespace App\Tests\Functional\Content;

use App\Enum\UserRole;
use App\Tests\Functional\ApiTestCase;

/**
 * Кто что видит в журнале, академии и конкурсах — как JournalDownloadTest,
 * AcademyAccessTest и ContestVotingTest.
 */
final class ContentAccessTest extends ApiTestCase
{
    /** @param array<string, mixed> $extra */
    private function journal(string $slug, string $status = 'published', array $extra = []): int
    {
        $this->db()->insert('journal_issues', $extra + [
            'slug' => $slug, 'title' => 'Гайд '.$slug, 'period' => 'week', 'status' => $status,
            'issue_date' => '2026-01-12', 'issue_year' => 2026, 'issue_month' => 1,
            'published_at' => 'published' === $status ? '2026-01-12 10:00:00' : null,
            'moderation_note' => 'Заметка редакции',
        ]);

        return (int) $this->db()->lastInsertId();
    }

    /** @param array<string, mixed> $extra */
    private function academy(string $slug, array $extra = []): int
    {
        $this->db()->insert('academy_sessions', $extra + [
            'slug' => $slug, 'title' => 'Занятие', 'format' => 'lesson', 'level' => 'basic', 'status' => 'published',
            'published_at' => '2026-01-01 00:00:00', 'record_url' => 'https://example.com/record', 'stream_url' => 'https://example.com/live',
            'takeaways' => "Тихий рез\nМелкая нарезка",
        ]);

        return (int) $this->db()->lastInsertId();
    }

    public function testJournalDraftIsVisibleOnlyToAuthorAndEditorLead(): void
    {
        $author = $this->user(UserRole::ContentManager);
        $this->journal('chernovik', 'draft', ['author_id' => $author->getId()]);

        $this->json('GET', '/api/v1/journal/chernovik');
        self::assertResponseStatusCodeSame(404);

        $this->login($this->user());
        $this->json('GET', '/api/v1/journal/chernovik');
        self::assertResponseStatusCodeSame(404);
    }

    public function testJournalDraftOpensForItsAuthor(): void
    {
        $author = $this->user(UserRole::ContentManager);
        $this->journal('moi-chernovik', 'draft', ['author_id' => $author->getId()]);
        $this->login($author);

        $body = $this->json('GET', '/api/v1/journal/moi-chernovik');

        self::assertResponseIsSuccessful();
        self::assertSame('Заметка редакции', $body['data']['moderation_note']);
    }

    public function testGuestDoesNotSeeStaffFieldsOfIssue(): void
    {
        $this->journal('vypusk');

        $data = $this->json('GET', '/api/v1/journal/vypusk')['data'];

        self::assertArrayNotHasKey('moderation_note', $data);
        self::assertArrayNotHasKey('file_name', $data);
        self::assertSame('12–18 января 2026', $data['period_range']);
    }

    public function testDownloadWithoutFileIs404(): void
    {
        $this->journal('bez-faila');

        $this->client->request('GET', '/api/v1/journal/bez-faila/download');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRecordLinkOnlyForRegisteredVerifiedUser(): void
    {
        $session = $this->academy('urok');

        $guest = $this->json('GET', '/api/v1/academy/urok')['data'];
        self::assertArrayNotHasKey('record_url', $guest);
        self::assertFalse($guest['viewer']['can_watch']);
        // Флаг u в разборе пунктов: «х» внутри строки не ломает ответ.
        self::assertSame(['Тихий рез', 'Мелкая нарезка'], $guest['takeaways']);

        $reader = $this->user();
        $this->login($reader);
        self::assertArrayNotHasKey('record_url', $this->json('GET', '/api/v1/academy/urok')['data']);

        $this->db()->insert('academy_registrations', ['academy_session_id' => $session, 'user_id' => $reader->getId()]);
        $data = $this->json('GET', '/api/v1/academy/urok')['data'];
        self::assertSame('https://example.com/record', $data['record_url']);
        // Видеоурок не идёт в эфире — ссылки на эфир у читателя нет.
        self::assertArrayNotHasKey('stream_url', $data);
    }

    public function testStreamLinkAppearsOnlyWhileLive(): void
    {
        $session = $this->academy('efir', [
            'format' => 'stream',
            'starts_at' => (new \DateTimeImmutable('-10 minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'duration_minutes' => 60,
        ]);
        $reader = $this->user();
        $this->db()->insert('academy_registrations', ['academy_session_id' => $session, 'user_id' => $reader->getId()]);
        $this->login($reader);

        $data = $this->json('GET', '/api/v1/academy/efir')['data'];

        self::assertTrue($data['is_live_now']);
        self::assertSame('https://example.com/live', $data['stream_url']);
    }

    public function testStaffSeesBothLinksAlways(): void
    {
        $this->academy('dlya-redakcii');
        $this->login($this->user(UserRole::ContentManager));

        $data = $this->json('GET', '/api/v1/academy/dlya-redakcii')['data'];

        self::assertSame('https://example.com/live', $data['stream_url']);
        self::assertSame('https://example.com/record', $data['record_url']);
    }

    public function testContestViewerStateForParticipant(): void
    {
        $this->db()->insert('contests', [
            'slug' => 'foto', 'title' => 'Фото', 'kind' => 'photo', 'status' => 'published', 'published_at' => '2026-01-01 00:00:00',
            'submissions_open_at' => '2026-01-01 00:00:00', 'submissions_close_at' => '2030-01-01 00:00:00', 'max_entries_per_user' => 2,
        ]);
        $contest = (int) $this->db()->lastInsertId();
        $me = $this->user();
        $this->db()->insert('contest_entries', ['contest_id' => $contest, 'user_id' => $me->getId(), 'status' => 'rejected', 'reject_reason' => 'Не по теме']);
        $this->login($me);

        $data = $this->json('GET', '/api/v1/contests/foto')['data'];

        self::assertSame('submissions', $data['phase']);
        self::assertTrue($data['accepts_entries']);
        self::assertTrue($data['viewer']['can_submit']);
        self::assertSame(1, $data['viewer']['entries_left']);
        // Причину отклонения автор видит, в «мои работы» автор не подгружается.
        self::assertSame('Не по теме', $data['viewer']['my_entries'][0]['reject_reason']);
        self::assertArrayNotHasKey('author', $data['viewer']['my_entries'][0]);
    }

    public function testLeaderboardShowsOnlyVerifiedActiveReaders(): void
    {
        $reader = $this->user();
        $this->db()->update('users', ['rating_points' => 50], ['id' => $reader->getId()]);
        $staff = $this->user(UserRole::EditorLead);
        $this->db()->update('users', ['rating_points' => 500], ['id' => $staff->getId()]);
        $this->login($reader);

        $body = $this->json('GET', '/api/v1/rating');

        $ids = array_column($body['data'], 'id');
        self::assertContains($reader->getId(), $ids);
        self::assertNotContains($staff->getId(), $ids);
        $mine = array_values(array_filter($body['data'], static fn (array $r): bool => $r['is_me']));
        self::assertSame($reader->getId(), $mine[0]['id']);
        self::assertSame(['value', 'label', 'threshold'], array_keys($body['meta']['levels'][0]));
    }
}
