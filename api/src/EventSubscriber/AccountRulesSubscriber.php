<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Rating\RatingService;
use App\Security\Attribute\TrackVisit;
use App\Security\Attribute\VerifiedEmail;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Атрибуты #[VerifiedEmail] и #[TrackVisit] на контроллерах личного кабинета.
 */
final class AccountRulesSubscriber implements EventSubscriberInterface
{
    private const VISIT = '_track_visit_user';

    /** Запрос уже отклонён проверкой почты — лимит маршрута на него не тратится. */
    public const BLOCKED = '_account_blocked';

    public function __construct(
        private readonly Security $security,
        private readonly RatingService $rating,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // После IsGranted (у него приоритет 20): гость получает 401, а не 403 «подтвердите почту».
            KernelEvents::CONTROLLER_ARGUMENTS => ['onControllerArguments', 10],
            KernelEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        // Сессия живёт дольше решения админа: отключённого выбрасываем на каждом запросе.
        if (!$user->isActive()) {
            $event->setController(static fn (): JsonResponse => new JsonResponse([
                'message' => 'Аккаунт отключён. Если это ошибка — напишите в поддержку.',
                'code' => 'account_disabled',
            ], 403));

            return;
        }

        if ([] !== $event->getAttributes(TrackVisit::class)) {
            $event->getRequest()->attributes->set(self::VISIT, $user);
        }

        if ([] !== $event->getAttributes(VerifiedEmail::class) && !$user->isVerified()) {
            $event->getRequest()->attributes->set(self::BLOCKED, true);
            $event->setController(static fn (): JsonResponse => new JsonResponse([
                'message' => 'Подтвердите адрес почты — мы отправили письмо со ссылкой.',
                'code' => 'email_unverified',
            ], 403));
        }
    }

    /**
     * Отметка ставится после ответа: заход не должен задерживать отдачу страницы,
     * а если начисление упадёт, человек всё равно получит свой ответ.
     */
    public function onTerminate(TerminateEvent $event): void
    {
        $user = $event->getRequest()->attributes->get(self::VISIT);
        if (!$user instanceof User || !$user->isVerified() || $user->hasVisitedOn($this->clock->now())) {
            return;
        }

        try {
            $this->rating->registerVisit((int) $user->getId());
        } catch (\Throwable $e) {
            // Баллы — не то, ради чего стоит ронять запрос.
            $this->logger->error('Не удалось отметить заход', ['exception' => $e]);
        }
    }
}
