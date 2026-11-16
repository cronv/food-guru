<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Действия вошедшего пользователя — как RecipeEngagementTest, HomeFeedTest
 * (рецептосохранялка), ReaderRecipeTest и RatingServiceTest.
 */
final class AccountActionsTest extends ApiTestCase
{
    /** @return array<string, string> */
    private function csrf(): array
    {
        return $this->xsrf();
    }

    private function reader(bool $verified = true): User
    {
        $user = $this->user();
        if (!$verified) {
            $this->db()->update('users', ['email_verified_at' => null], ['id' => $user->getId()]);
        }
        $this->login($user);

        return $user;
    }

    private function png(string $name = 'photo.png'): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(6)).'.png';
        // Настоящий PNG 1×1: тип определяется по содержимому, а не по имени.
        file_put_contents($path, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    public function testGuestGets401AndUnverifiedGets403(): void
    {
        $this->json('GET', '/api/v1/me/favorites');
        self::assertResponseStatusCodeSame(401);

        $this->reader(verified: false);
        $body = $this->json('GET', '/api/v1/me/favorites');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('email_unverified', $body['code']);
    }

    public function testDailyVisitIsAwardedOncePerDay(): void
    {
        $user = $this->reader();

        $this->json('GET', '/api/v1/me/favorites');
        $this->json('GET', '/api/v1/me/cookbook/recipes');

        $visits = $this->db()->fetchAllAssociative("SELECT * FROM rating_events WHERE user_id = ? AND source = 'visit'", [$user->getId()]);
        self::assertCount(1, $visits);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT visits_total FROM users WHERE id = ?', [$user->getId()]));
    }

    public function testFavoriteIsIdempotentAndListedNewestFirst(): void
    {
        $first = $this->insertRecipe('pervyi', null);
        $second = $this->insertRecipe('vtoroi', null);
        $this->reader();

        $this->json('PUT', "/api/v1/recipes/{$first}/favorite", $this->csrf(), []);
        $this->json('PUT', "/api/v1/recipes/{$second}/favorite", $this->csrf(), []);
        $this->db()->executeStatement('UPDATE favorites SET created_at = DATE_SUB(created_at, INTERVAL 2 HOUR) WHERE recipe_id = ?', [$first]);
        $this->db()->executeStatement('UPDATE favorites SET created_at = DATE_SUB(created_at, INTERVAL 1 HOUR) WHERE recipe_id = ?', [$second]);
        // Повторное нажатие не падает и поднимает рецепт наверх (дата обновляется).
        self::assertSame(['favorite' => true], $this->json('PUT', "/api/v1/recipes/{$first}/favorite", $this->csrf(), [])['data']);

        self::assertSame(['pervyi', 'vtoroi'], array_column($this->json('GET', '/api/v1/me/favorites')['data'], 'slug'));
    }

    public function testDraftCannotBeFavorited(): void
    {
        $draft = $this->insertRecipe('chernovik', null, 'draft', null);
        $this->reader();

        $this->json('PUT', "/api/v1/recipes/{$draft}/favorite", $this->csrf(), []);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCookbookStateCountsSaves(): void
    {
        $recipe = $this->insertRecipe('v-knigu', null);
        $this->reader();

        $state = $this->json('PUT', "/api/v1/recipes/{$recipe}/cookbook", $this->csrf(), [])['data'];
        self::assertSame(['in_cookbook' => true, 'saves_count' => 1], $state);

        $state = $this->json('DELETE', "/api/v1/recipes/{$recipe}/cookbook", $this->csrf(), [])['data'];
        self::assertSame(['in_cookbook' => false, 'saves_count' => 0], $state);
    }

    public function testNotesNeedUrlOrTextAndForeignNoteIs404(): void
    {
        $this->reader();

        $body = $this->json('POST', '/api/v1/me/cookbook/notes', $this->csrf(), ['title' => 'Борщ']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['body' => ['Добавьте ссылку на рецепт или запишите его текст.']], $body['errors']);

        $this->json('POST', '/api/v1/me/cookbook/notes', $this->csrf(), ['title' => 'Борщ', 'url' => 'javascript:alert(1)']);
        self::assertSame(['url' => ['Ссылка должна начинаться с http:// или https://.']], json_decode((string) $this->client->getResponse()->getContent(), true)['errors']);

        $note = $this->json('POST', '/api/v1/me/cookbook/notes', $this->csrf(), ['title' => 'Борщ', 'body' => 'Свёкла, капуста'])['data'];
        self::assertResponseStatusCodeSame(201);

        $stranger = $this->user();
        $this->db()->insert('cookbook_notes', ['user_id' => $stranger->getId(), 'title' => 'Чужая', 'body' => 'Секрет']);
        $foreign = (int) $this->db()->lastInsertId();
        $this->json('PATCH', "/api/v1/me/cookbook/notes/{$foreign}", $this->csrf(), ['title' => 'Моя', 'body' => 'x']);
        self::assertResponseStatusCodeSame(404);

        $this->json('DELETE', "/api/v1/me/cookbook/notes/{$note['id']}", $this->csrf(), []);
        self::assertResponseIsSuccessful();
    }

    public function testReviewWithPhotoAwardsCommentAndPhotoReportOnce(): void
    {
        $this->insertRecipe('s-foto', null);
        $user = $this->reader();

        $this->client->request('POST', '/api/v1/recipes/s-foto/reviews', ['rating' => '5', 'body' => 'Получилось с первого раза, спасибо!'], ['photos' => [$this->png()]], $this->csrf() + ['HTTP_ORIGIN' => self::SITE, 'HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(201);
        $review = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $review['media']);
        self::assertStringEndsWith('.png', $review['media'][0]['url']);

        $sources = $this->db()->fetchFirstColumn('SELECT source FROM rating_events WHERE user_id = ? AND source != ? ORDER BY id', [$user->getId(), 'visit']);
        self::assertSame(['comment', 'photo_report'], $sources);

        $this->json('POST', '/api/v1/recipes/s-foto/reviews', $this->csrf(), ['rating' => 4, 'body' => 'Второй отзыв на тот же рецепт']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testPhpFileDisguisedAsImageIsRejected(): void
    {
        $this->insertRecipe('bez-skriptov', null);
        $this->reader();
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(6)).'.php';
        file_put_contents($path, '<?php echo 1;');

        $this->client->request('POST', '/api/v1/recipes/bez-skriptov/reviews', ['rating' => '5', 'body' => 'Попытка подсунуть скрипт'], ['photos' => [new UploadedFile($path, 'photo.png', 'image/png', null, true)]], $this->csrf() + ['HTTP_ORIGIN' => self::SITE, 'HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testForeignReviewDeletionNeedsStaff(): void
    {
        $recipe = $this->insertRecipe('chuzhoi-otzyv', null);
        $author = $this->user();
        $this->db()->insert('reviews', ['recipe_id' => $recipe, 'user_id' => $author->getId(), 'rating' => 5, 'body' => 'Отзыв автора']);
        $review = (int) $this->db()->lastInsertId();

        $this->reader();
        $this->json('DELETE', "/api/v1/reviews/{$review}", $this->csrf(), []);
        self::assertResponseStatusCodeSame(403);
    }

    public function testReactionsRecountFromTable(): void
    {
        $recipe = $this->insertRecipe('ocenki', null);
        $this->reader();

        self::assertSame(['likes_count' => 1, 'dislikes_count' => 0, 'my_reaction' => 'like'], $this->json('PUT', "/api/v1/recipes/{$recipe}/reaction", $this->csrf(), ['reaction' => 'like'])['data']);
        self::assertSame(['likes_count' => 0, 'dislikes_count' => 1, 'my_reaction' => 'dislike'], $this->json('PUT', "/api/v1/recipes/{$recipe}/reaction", $this->csrf(), ['reaction' => 'dislike'])['data']);
        self::assertSame(['likes_count' => 0, 'dislikes_count' => 0, 'my_reaction' => null], $this->json('DELETE', "/api/v1/recipes/{$recipe}/reaction", $this->csrf(), [])['data']);
    }

    public function testReaderRecipeDropsUndeclaredFieldsAndForeignPhotos(): void
    {
        $user = $this->reader();
        $stranger = $this->user(UserRole::ContentManager);
        $this->db()->insert('media', ['uploaded_by' => $stranger->getId(), 'path' => 'uploads/x.png', 'disk' => 'public']);
        $foreignMedia = (int) $this->db()->lastInsertId();

        $this->json('POST', '/api/v1/me/recipes', $this->csrf(), ['title' => 'Суп', 'cover_media_id' => $foreignMedia]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['cover_media_id' => ['Фото не найдено — загрузите его заново.']], json_decode((string) $this->client->getResponse()->getContent(), true)['errors']);

        // «Секрет шефа» читателю не положен: в правилах его нет, и в базу он не попадает.
        $created = $this->json('POST', '/api/v1/me/recipes', $this->csrf(), [
            'title' => 'Хачапури по-аджарски',
            'steps' => [['body' => 'Замесить тесто', 'chef_secret' => 'Протащил секрет']],
            'tags' => ['На ужин'],
        ])['data'];
        self::assertResponseStatusCodeSame(201);
        self::assertSame('xacapuri-po-adzarski', $created['slug']);
        self::assertSame('draft', $created['status']);
        self::assertNull($this->db()->fetchOne('SELECT chef_secret FROM recipe_steps WHERE recipe_id = ?', [$created['id']]));
        self::assertSame([['slug' => 'na-uzin']], array_map(static fn (array $t): array => ['slug' => $t['slug']], $created['tags']));
        self::assertGreaterThan(0, $user->getId());
    }

    public function testSubmitListsWhatIsMissingAndPublishedIsNotEditable(): void
    {
        $user = $this->reader();
        $id = $this->json('POST', '/api/v1/me/recipes', $this->csrf(), ['title' => 'Пустой рецепт'])['data']['id'];

        $body = $this->json('POST', "/api/v1/me/recipes/{$id}/submit", $this->csrf(), []);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['cover_media_id', 'lead', 'ingredients', 'steps', 'time_minutes'], array_keys($body['errors']));

        $this->db()->update('recipes', ['status' => 'published', 'published_at' => '2026-01-01 00:00:00'], ['id' => $id]);
        $body = $this->json('PATCH', "/api/v1/me/recipes/{$id}", $this->csrf(), ['title' => 'Хочу поменять']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('not_editable', $body['code']);
        self::assertGreaterThan(0, $user->getId());
    }

    public function testAcademyRegistrationCountsOnce(): void
    {
        $this->db()->insert('academy_sessions', ['slug' => 'zapis', 'title' => 'Урок', 'format' => 'lesson', 'level' => 'basic', 'status' => 'published', 'published_at' => '2026-01-01 00:00:00', 'record_url' => 'https://example.com/r']);
        $session = (int) $this->db()->lastInsertId();
        $this->reader();

        $this->json('PUT', '/api/v1/academy/zapis/registration', $this->csrf(), []);
        $data = $this->json('PUT', '/api/v1/academy/zapis/registration', $this->csrf(), [])['data'];

        self::assertTrue($data['viewer']['registered']);
        self::assertSame('https://example.com/r', $data['record_url']);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT registrations_count FROM academy_sessions WHERE id = ?', [$session]));

        $this->json('DELETE', '/api/v1/academy/zapis/registration', $this->csrf(), []);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT registrations_count FROM academy_sessions WHERE id = ?', [$session]));
    }

    public function testVotingRulesAndOneVotePerContest(): void
    {
        $this->db()->insert('contests', [
            'slug' => 'golosuem', 'title' => 'Голосуем', 'kind' => 'photo', 'status' => 'published', 'published_at' => '2026-01-01 00:00:00',
            'submissions_open_at' => '2026-01-01 00:00:00', 'submissions_close_at' => '2026-01-02 00:00:00', 'voting_open_at' => '2026-01-03 00:00:00', 'voting_close_at' => '2030-01-01 00:00:00',
        ]);
        $contest = (int) $this->db()->lastInsertId();
        $me = $this->reader();
        $other = $this->user();
        $this->db()->insert('contest_entries', ['contest_id' => $contest, 'user_id' => $other->getId(), 'status' => 'approved']);
        $theirs = (int) $this->db()->lastInsertId();
        $this->db()->insert('contest_entries', ['contest_id' => $contest, 'user_id' => $me->getId(), 'status' => 'approved']);
        $mine = (int) $this->db()->lastInsertId();

        self::assertSame('own_entry', $this->json('POST', "/api/v1/contests/golosuem/entries/{$mine}/vote", $this->csrf(), [])['code']);
        self::assertSame(['entry_id' => $theirs, 'votes_count' => 1], $this->json('POST', "/api/v1/contests/golosuem/entries/{$theirs}/vote", $this->csrf(), [])['data']);
        self::assertSame('already_voted', $this->json('POST', "/api/v1/contests/golosuem/entries/{$theirs}/vote", $this->csrf(), [])['code']);
        self::assertResponseStatusCodeSame(409);
    }
}
