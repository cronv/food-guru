<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Enum\UserRole;
use App\Tests\Functional\ApiTestCase;

/**
 * Сессии Symfony: вход узнаётся, гость получает 401, отключённый аккаунт — 403,
 * изменяющие запросы с фронтенда проверяют XSRF-TOKEN.
 */
final class SessionAuthTest extends ApiTestCase
{
    public function testGuestGets401(): void
    {
        $body = $this->json('GET', '/api/v1/_probe');

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['message' => 'Unauthenticated.'], $body);
    }

    public function testLoggedInUserIsRecognised(): void
    {
        $user = $this->user(UserRole::ContentManager);
        $this->login($user);

        $body = $this->json('GET', '/api/v1/_probe');

        self::assertResponseIsSuccessful();
        self::assertSame($user->getId(), $body['id']);
        self::assertSame(['ROLE_CONTENT_MANAGER'], $body['roles']);
    }

    public function testContentManagerCannotOpenAdminProbe(): void
    {
        $this->login($this->user(UserRole::ContentManager));
        $this->json('GET', '/api/v1/_probe/admin');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminHasAllLowerRoles(): void
    {
        $this->login($this->user(UserRole::Admin));
        $this->json('GET', '/api/v1/_probe/admin');
        self::assertResponseIsSuccessful();
    }

    public function testDisabledAccountIsRejectedEvenWithOpenSession(): void
    {
        $this->login($this->user(active: false));

        $body = $this->json('GET', '/api/v1/_probe');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('account_disabled', $body['code']);
    }

    public function testWriteWithoutCsrfTokenGets419(): void
    {
        $this->login($this->user());

        $body = $this->json('POST', '/api/v1/_probe', body: []);

        self::assertResponseStatusCodeSame(419);
        self::assertSame(['message' => 'CSRF token mismatch.'], $body);
    }

    public function testWriteWithXsrfHeaderPasses(): void
    {
        $this->login($this->user());

        $this->json('POST', '/api/v1/_probe', $this->xsrf(), []);

        self::assertResponseIsSuccessful();
    }

    public function testForgedCsrfTokenIsRejected(): void
    {
        $this->login($this->user());

        $this->json('POST', '/api/v1/_probe', ['HTTP_X_XSRF_TOKEN' => 'guess'], []);
        self::assertResponseStatusCodeSame(419);
    }

    public function testGuestWriteFromFrontendWithoutTokenGets419(): void
    {
        $this->json('POST', '/api/v1/_probe/public', body: []);
        self::assertResponseStatusCodeSame(419);
    }

    public function testWriteNotFromFrontendIsNotCsrfChecked(): void
    {
        // Без Origin/Referer (сервер, curl) CSRF не проверяется.
        $this->client->request('POST', '/api/v1/_probe/public', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
    }
}
