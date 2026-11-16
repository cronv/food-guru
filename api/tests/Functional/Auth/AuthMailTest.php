<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Tests\Functional\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mime\Email;

/** Письма подтверждения и входа: без отправителя Symfony Mailer их не отправит. */
final class AuthMailTest extends ApiTestCase
{
    use MailerAssertionsTrait;

    public function testRegistrationSendsVerificationMail(): void
    {
        $this->json('POST', '/api/v1/auth/register', $this->xsrf(), [
            'name' => 'Новый читатель',
            'email' => 'new-'.bin2hex(random_bytes(3)).'@example.com',
        ]);

        self::assertResponseStatusCodeSame(202);
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertNotEmpty($message->getFrom());
    }
}
