<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Api\ApiError;
use App\Api\Validation\ValidationFailed;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Ошибки API — в формате {"message": "..."} с тем же кодом. Фронтенд
 * читает поле message (errorText() в shared), так что формат менять нельзя.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -10)]
final class ApiExceptionSubscriber
{
    private const MESSAGES = [
        401 => 'Unauthenticated.',
        403 => 'This action is unauthorized.',
        404 => 'Not Found.',
        405 => 'Method Not Allowed.',
        419 => 'CSRF token mismatch.',
        429 => 'Too Many Attempts.',
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $e = $event->getThrowable();

        if ($e instanceof ApiError) {
            $event->setResponse(new JsonResponse($e->body(), $e->getStatusCode()));

            return;
        }

        if ($e instanceof ValidationFailed) {
            $event->setResponse(new JsonResponse(['message' => $e->getMessage(), 'errors' => $e->errors], 422));

            return;
        }

        // Отказы доступа обрабатывает firewall: гостю — 401 через entry point.
        if ($e instanceof AuthenticationException || $e instanceof AccessDeniedException) {
            return;
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            // Свой текст ошибки (abort(403, '…')) — как есть. У 404/405 — всегда
            // стандартный: сообщения маршрутизатора Symfony выдают внутренние пути.
            $message = \in_array($status, [404, 405], true) || '' === $e->getMessage()
                ? (self::MESSAGES[$status] ?? 'Error')
                : $e->getMessage();
            $event->setResponse(new JsonResponse(['message' => $message], $status, $e->getHeaders()));

            return;
        }

        // Неожиданная ошибка: подробности — в лог, наружу — только в режиме отладки.
        $this->logger->error($e->getMessage(), ['exception' => $e]);
        $event->setResponse(new JsonResponse(
            ['message' => $this->debug ? $e->getMessage() : 'Server Error'],
            500,
        ));
    }
}
