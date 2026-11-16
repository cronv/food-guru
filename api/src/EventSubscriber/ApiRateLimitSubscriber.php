<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\Attribute\RouteThrottle;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Лимит частоты на весь API (throttleApi() + лимитер «api»),
 * с теми же заголовками X-RateLimit-* и ответом 429.
 *
 * SSR-сервер сайта ходит в API за всех посетителей с одного IP. С верным
 * X-SSR-Key лимит считается по адресу посетителя из X-Client-IP; без ключа
 * этому заголовку не верим.
 */
final class ApiRateLimitSubscriber implements EventSubscriberInterface
{
    private const ATTRIBUTE = '_api_rate_limit';

    public function __construct(
        #[Autowire(service: 'limiter.api')]
        private readonly RateLimiterFactoryInterface $apiLimiter,
        #[Autowire(service: 'limiter.api_ssr')]
        private readonly RateLimiterFactoryInterface $ssrLimiter,
        private readonly Security $security,
        #[Autowire(env: 'SSR_API_KEY')]
        #[\SensitiveParameter]
        private readonly string $ssrKey,
        #[Autowire(service: 'cache.rate_limiter')]
        private readonly CacheItemPoolInterface $routePool,
    ) {
    }

    /** @var array<string, RateLimiterFactory> */
    private array $routeLimiters = [];

    public static function getSubscribedEvents(): array
    {
        return [
            // После firewall (8): лимит на вошедшего считается по его id.
            KernelEvents::REQUEST => ['onRequest', 7],
            // После IsGranted (20) и VerifiedEmail (10): throttle маршрута стоит после auth и email.verified.
            KernelEvents::CONTROLLER_ARGUMENTS => ['onControllerArguments', 5],
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    /** Свой лимит маршрута (#[RouteThrottle]) — его заголовки заменяют общие. */
    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        $throttle = $event->getAttributes(RouteThrottle::class)[0] ?? null;
        $request = $event->getRequest();
        if (!$throttle instanceof RouteThrottle || $request->attributes->get(AccountRulesSubscriber::BLOCKED)) {
            return;
        }

        $factory = $this->routeLimiters[$throttle->limit.'/'.$throttle->minutes] ??= new RateLimiterFactory([
            'id' => 'route_'.$throttle->limit.'_'.$throttle->minutes,
            'policy' => 'fixed_window',
            'limit' => $throttle->limit,
            'interval' => $throttle->minutes.' minutes',
        ], new CacheStorage($this->routePool));

        $key = $request->attributes->get('_route').':'.($this->security->getUser()?->getUserIdentifier() ?? 'ip:'.$request->getClientIp());
        $limit = $factory->create($key)->consume();
        $request->attributes->set(self::ATTRIBUTE, $limit);

        if (!$limit->isAccepted()) {
            $event->setController(function () use ($limit): JsonResponse {
                $response = new JsonResponse(['message' => 'Too Many Attempts.'], 429);
                $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));
                $this->addHeaders($response->headers, $limit);

                return $response;
            });
        }
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $visitor = $this->ssrVisitorIp($request);
        $limiter = null !== $visitor
            ? $this->ssrLimiter->create('ssr:'.$visitor)
            : $this->apiLimiter->create($this->security->getUser()?->getUserIdentifier() ?? 'ip:'.$request->getClientIp());

        $limit = $limiter->consume();
        $request->attributes->set(self::ATTRIBUTE, $limit);

        if (!$limit->isAccepted()) {
            $response = new JsonResponse(['message' => 'Too Many Attempts.'], 429);
            $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));
            $this->addHeaders($response->headers, $limit);
            $event->setResponse($response);
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        // Авторизация по приоритету стоит раньше throttle: гостю на закрытом
        // адресе отвечают 401 без заголовков лимита.
        if (401 === $event->getResponse()->getStatusCode() && null === $this->security->getUser()) {
            return;
        }

        $limit = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if ($limit instanceof RateLimit && $event->isMainRequest()) {
            $this->addHeaders($event->getResponse()->headers, $limit);
        }
    }

    private function ssrVisitorIp(Request $request): ?string
    {
        $given = (string) $request->headers->get('X-SSR-Key');
        if ('' === $this->ssrKey || '' === $given || !hash_equals($this->ssrKey, $given)) {
            return null;
        }

        $ip = filter_var($request->headers->get('X-Client-IP'), \FILTER_VALIDATE_IP);

        return false === $ip ? 'unknown' : $ip;
    }

    private function addHeaders(ResponseHeaderBag $headers, RateLimit $limit): void
    {
        $headers->set('X-RateLimit-Limit', (string) $limit->getLimit());
        $headers->set('X-RateLimit-Remaining', (string) $limit->getRemainingTokens());
    }
}
