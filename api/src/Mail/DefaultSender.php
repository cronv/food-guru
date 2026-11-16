<?php

declare(strict_types=1);

namespace App\Mail;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;

/**
 * Отправитель по умолчанию для всех писем: без From Symfony Mailer письмо не отправит.
 * Адрес берётся из MAIL_FROM_ADDRESS (например «Food Guru <no-reply@food-guru.ru>»).
 */
#[AsEventListener]
final class DefaultSender
{
    public function __construct(
        #[Autowire(env: 'MAIL_FROM_ADDRESS')]
        private readonly string $from,
    ) {
    }

    public function __invoke(MessageEvent $event): void
    {
        $message = $event->getMessage();
        if ($message instanceof Email && [] === $message->getFrom() && null === $message->getSender()) {
            $message->from($this->from);
        }
    }
}
