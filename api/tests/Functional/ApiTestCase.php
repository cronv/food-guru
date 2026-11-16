<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Security\Xsrf;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * База тестов API: клиент с заголовками фронтенда и помощники для данных.
 * Каждый тест — в транзакции, которую DAMA откатывает (phpunit.dist.xml).
 */
abstract class ApiTestCase extends WebTestCase
{
    protected const SITE = 'http://localhost:4200';

    protected KernelBrowser $client;

    private string $xsrfToken;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Одно ядро на весь тест: иначе счётчики лимитов в памяти обнулялись бы на каждом запросе.
        $this->client->disableReboot();
        static::getContainer()->get('cache.rate_limiter_test')->clear();

        // Фронтенд получает XSRF-TOKEN от API; здесь — тот же токен в cookie и в заголовке.
        $this->xsrfToken = (new Xsrf(static::getContainer()->getParameter('kernel.secret')))->issue();
        $this->client->getCookieJar()->set(new Cookie(Xsrf::COOKIE, $this->xsrfToken));
    }

    /** @return array<string, string> */
    protected function xsrf(): array
    {
        return ['HTTP_X_XSRF_TOKEN' => $this->xsrfToken];
    }

    protected function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    /**
     * @param array<string, string>     $headers HTTP-заголовки в виде 'HTTP_X_...' => значение
     * @param array<string, mixed>|null $body
     */
    protected function json(string $method, string $uri, array $headers = [], ?array $body = null): mixed
    {
        $this->client->request($method, $uri, server: $headers + [
            'HTTP_ORIGIN' => self::SITE,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], content: null === $body ? null : (string) json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    protected function user(UserRole $role = UserRole::User, bool $active = true): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('Тест', 'u'.bin2hex(random_bytes(4)).'@example.com');
        $user->setRole($role);
        $user->setActive($active);
        $user->markVerified(new \DateTimeImmutable());
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** Вход в сессию Symfony, как после подтверждения ссылки из письма. */
    protected function login(User $user): void
    {
        $this->client->loginUser($user, 'api');
    }

    protected function insertRecipe(string $slug, ?int $categoryId, string $status = 'published', ?string $publishedAt = '-1 day'): int
    {
        $author = $this->db()->fetchOne('SELECT id FROM users ORDER BY id LIMIT 1') ?: $this->user()->getId();
        $this->db()->insert('recipes', [
            'author_id' => $author,
            'category_id' => $categoryId,
            'slug' => $slug,
            'title' => $slug,
            'status' => $status,
            'published_at' => null === $publishedAt ? null : (new \DateTimeImmutable($publishedAt, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->db()->lastInsertId();
    }
}
