<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CSRF для изменяющих запросов с cookie-сессией. Фронтенд шлёт cookie XSRF-TOKEN
 * в заголовке X-XSRF-TOKEN; значение должно совпасть с cookie и быть подписанным.
 *
 * Проверяем только запросы с своих фронтендов (STATEFUL_DOMAINS), как Sanctum:
 * сервер и curl без Origin/Referer CSRF не касается.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onRequest', priority: 9)]
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onResponse')]
final class XsrfSubscriber
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** @var list<string> */
    private readonly array $statefulDomains;

    public function __construct(
        private readonly Xsrf $xsrf,
        #[Autowire(env: 'SESSION_DOMAIN')]
        private readonly string $sessionDomain,
        #[Autowire(env: 'STATEFUL_DOMAINS')]
        string $statefulDomains,
    ) {
        $this->statefulDomains = array_values(array_filter(array_map('trim', explode(',', $statefulDomains))));
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest()
            || \in_array($request->getMethod(), self::SAFE_METHODS, true)
            || !str_starts_with($request->getPathInfo(), '/api/')
            || !$this->isFromStatefulFrontend($request)) {
            return;
        }

        $cookie = (string) $request->cookies->get(Xsrf::COOKIE);
        $header = (string) $request->headers->get(Xsrf::HEADER);

        if ('' === $cookie || '' === $header || !hash_equals($cookie, $header) || !$this->xsrf->isValid($header)) {
            $event->setResponse(new JsonResponse(['message' => 'CSRF token mismatch.'], 419));
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $current = (string) $request->cookies->get(Xsrf::COOKIE);
        if ('' !== $current && $this->xsrf->isValid($current)) {
            return;
        }

        $event->getResponse()->headers->setCookie(new Cookie(
            name: Xsrf::COOKIE,
            value: $this->xsrf->issue(),
            path: '/',
            domain: '' === $this->sessionDomain ? null : $this->sessionDomain,
            secure: $request->isSecure(),
            httpOnly: false,
            sameSite: Cookie::SAMESITE_LAX,
        ));
    }

    private function isFromStatefulFrontend(Request $request): bool
    {
        $source = $request->headers->get('Origin') ?: $request->headers->get('Referer');
        if (!$source) {
            return false;
        }

        $host = parse_url($source, \PHP_URL_HOST);
        if (!\is_string($host)) {
            return false;
        }

        $port = parse_url($source, \PHP_URL_PORT);
        $hostWithPort = null === $port ? $host : $host.':'.$port;

        return \in_array($hostWithPort, $this->statefulDomains, true)
            || \in_array($host, $this->statefulDomains, true);
    }
}
