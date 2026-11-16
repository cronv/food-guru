<?php

declare(strict_types=1);

namespace App\Tests\Functional\Commerce;

use App\Entity\User;
use App\Tests\Functional\ApiTestCase;

/** Платный доступ к книгам и закрытой академии (часть про чтение). */
final class PaidAccessTest extends ApiTestCase
{
    private function book(string $slug, int $price, bool $inSubscription = false): int
    {
        $this->db()->insert('books', ['slug' => $slug, 'title' => 'Книга '.$slug, 'price' => $price, 'in_subscription' => (int) $inSubscription, 'is_published' => 1, 'published_at' => '2026-01-01 00:00:00']);
        $id = (int) $this->db()->lastInsertId();
        $this->db()->insert('book_chapters', ['book_id' => $id, 'position' => 1, 'title' => 'Вступление', 'body' => 'Открыто всем.', 'is_preview' => 1]);
        $this->db()->insert('book_chapters', ['book_id' => $id, 'position' => 2, 'title' => 'Глава', 'body' => 'Только купившим.', 'is_preview' => 0]);

        return $id;
    }

    private function subscribe(User $user): void
    {
        $utc = new \DateTimeZone('UTC');
        $this->db()->insert('subscriptions', [
            'user_id' => $user->getId(), 'price' => 49000,
            'starts_at' => (new \DateTimeImmutable('-1 day', $utc))->format('Y-m-d H:i:s'),
            'ends_at' => (new \DateTimeImmutable('+29 days', $utc))->format('Y-m-d H:i:s'),
        ]);
    }

    public function testPaidBookPreviewIsOpenTheRestLocked(): void
    {
        $this->book('platnaya', 49000);

        $this->json('GET', '/api/v1/books/platnaya/chapters/1');
        self::assertResponseIsSuccessful();

        $body = $this->json('GET', '/api/v1/books/platnaya/chapters/2');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('locked', $body['code']);
        self::assertSame('Глава доступна после покупки книги.', $body['message']);
    }

    public function testBuyerReadsEverythingAndMayDownload(): void
    {
        $book = $this->book('kuplennaya', 49000);
        $buyer = $this->user();
        $this->db()->insert('book_purchases', ['user_id' => $buyer->getId(), 'book_id' => $book, 'price' => 49000]);
        $this->login($buyer);

        $card = $this->json('GET', '/api/v1/books/kuplennaya')['data'];
        self::assertTrue($card['owned']);
        self::assertTrue($card['can_download']);
        self::assertSame([true, true], array_column($card['chapters'], 'readable'));

        $chapter = $this->json('GET', '/api/v1/books/kuplennaya/chapters/2')['data'];
        self::assertSame('Только купившим.', $chapter['body']);
        self::assertSame(1, $chapter['prev']);
        self::assertNull($chapter['next']);
    }

    public function testSubscriberReadsSubscriptionBookButCannotDownload(): void
    {
        $this->book('po-podpiske', 89000, inSubscription: true);
        $reader = $this->user();
        $this->subscribe($reader);
        $this->login($reader);

        $card = $this->json('GET', '/api/v1/books/po-podpiske')['data'];

        self::assertTrue($card['can_read']);
        self::assertFalse($card['can_download']);
        self::assertFalse($card['owned']);
    }

    public function testMineFilterShowsOnlyPurchased(): void
    {
        $mine = $this->book('moya', 1000);
        $this->book('chuzhaya', 1000);
        $buyer = $this->user();
        $this->db()->insert('book_purchases', ['user_id' => $buyer->getId(), 'book_id' => $mine, 'price' => 1000]);
        $this->login($buyer);

        $slugs = array_column($this->json('GET', '/api/v1/books?status=mine')['data'], 'slug');

        self::assertSame(['moya'], $slugs);
    }

    public function testClubContentAndSignedVideoOnlyForSubscriber(): void
    {
        $this->db()->insert('club_items', ['slug' => 'master', 'kind' => 'masterclass', 'title' => 'Мастер-класс', 'body' => 'Секрет', 'is_published' => 1, 'published_at' => '2026-01-01 00:00:00', 'video_disk' => 'local', 'video_path' => 'club/net-faila.mp4']);

        $locked = $this->json('GET', '/api/v1/club/master')['data'];
        self::assertFalse($locked['unlocked']);
        self::assertArrayNotHasKey('body', $locked);
        self::assertArrayNotHasKey('video_url', $locked);

        $reader = $this->user();
        $this->subscribe($reader);
        $this->login($reader);
        $open = $this->json('GET', '/api/v1/club/master')['data'];
        self::assertSame('Секрет', $open['body']);
        self::assertIsString($open['video_url']);

        // Подпись верна, но файла нет — 404; подпись подделана — 403.
        $this->client->request('GET', $open['video_url']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', str_replace('user=', 'user=9', $open['video_url']));
        self::assertResponseStatusCodeSame(403);
    }
}
