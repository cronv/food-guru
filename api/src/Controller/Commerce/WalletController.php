<?php

declare(strict_types=1);

namespace App\Controller\Commerce;

use App\Api\Pagination\Paginator;
use App\Api\Validation\InputValidator;
use App\Catalog\RecipePresenter;
use App\Commerce\Payment\PaymentGatewayFactory;
use App\Commerce\Payment\PaymentService;
use App\Commerce\WalletService;
use App\Entity\User;
use App\Security\Attribute\RouteThrottle;
use App\Security\Attribute\TrackVisit;
use App\Security\Attribute\VerifiedEmail;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Кошелёк: баланс, история, пополнение и тестовая оплата. */
final class WalletController
{
    /** Пополнение, в копейках: от 100 до 50 000 ₽. */
    private const TOPUP_MIN = 10000;
    private const TOPUP_MAX = 5000000;

    public function __construct(
        private readonly Connection $db,
        private readonly WalletService $wallet,
        private readonly PaymentService $payments,
        private readonly PaymentGatewayFactory $gateways,
        private readonly InputValidator $validator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Способы пополнения и пределы суммы — форма берёт их отсюда, а не держит у себя. */
    #[Route('/payments/methods', name: 'payments_methods', methods: ['GET'])]
    public function methods(): JsonResponse
    {
        return new JsonResponse(['data' => [
            'methods' => array_map(static fn (string $code, string $label): array => ['code' => $code, 'label' => $label], array_keys(PaymentService::METHODS), PaymentService::METHODS),
            'min' => self::TOPUP_MIN,
            'max' => self::TOPUP_MAX,
            'test_mode' => $this->gateways->testMode(),
            'available' => null !== $this->gateways->gateway(),
        ]]);
    }

    /** Баланс и история операций. */
    #[Route('/me/wallet', name: 'wallet_show', methods: ['GET'])]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
    public function show(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $page = Paginator::currentPage($request);
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM wallet_transactions WHERE user_id = ?', [$user->getId()]);
        $rows = $this->db->fetchAllAssociative('SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 20 OFFSET '.(($page - 1) * 20), [$user->getId()]);

        return new JsonResponse([
            'data' => [
                'balance' => $this->wallet->balance((int) $user->getId()),
                'transactions' => array_map(static fn (array $t): array => [
                    'id' => (int) $t['id'],
                    'amount' => (int) $t['amount'],
                    'balance_after' => (int) $t['balance_after'],
                    'type' => $t['type'],
                    'description' => $t['description'],
                    'created_at' => RecipePresenter::iso($t['created_at']),
                ], $rows),
            ],
            'meta' => ['current_page' => $page, 'last_page' => max((int) ceil($total / 20), 1), 'total' => $total],
        ]);
    }

    /** Начать пополнение: создаём платёж и отдаём адрес страницы оплаты. */
    #[Route('/me/wallet/topups', name: 'wallet_topup', methods: ['POST'])]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit, RouteThrottle(limit: 20, minutes: 1)]
    public function topUp(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = $this->validator->validate($request, [
            // Сумма в копейках, целым числом.
            'amount' => 'required|integer|min:'.self::TOPUP_MIN.'|max:'.self::TOPUP_MAX,
            'method' => 'required|in:'.implode(',', array_keys(PaymentService::METHODS)),
        ], [
            'amount.min' => 'Минимальное пополнение — '.intdiv(self::TOPUP_MIN, 100).' ₽.',
            'amount.max' => 'За один раз можно пополнить не больше чем на '.number_format(intdiv(self::TOPUP_MAX, 100), 0, ',', ' ').' ₽.',
            'method.in' => 'Выберите способ пополнения.',
        ]);

        $gateway = $this->gateways->gateway();
        if (null === $gateway) {
            $this->logger->error('Пополнение недоступно: платёжная система не настроена (PAYMENT_PROVIDER).');

            return new JsonResponse(['message' => 'Пополнение временно недоступно. Попробуйте позже.'], 503);
        }

        $payment = $this->payments->createTopUp($gateway, (int) $user->getId(), (int) $data['amount'], (string) $data['method']);

        return new JsonResponse(['data' => PaymentService::present($payment)], 201);
    }

    /** Статус платежа — для страницы, куда покупатель возвращается после оплаты. */
    #[Route('/payments/{payment}', name: 'payment_show', requirements: ['payment' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
    public function payment(string $payment, #[CurrentUser] User $user): JsonResponse
    {
        $row = $this->own($payment, $user);

        return new JsonResponse(['data' => PaymentService::present($row) + ['balance' => $this->wallet->balance((int) $user->getId())]]);
    }

    /**
     * Ответ тестовой платёжной системы: «оплатить» или «отменить». Работает только
     * для платежей тестового провайдера — настоящий платёж так не подтвердить.
     */
    #[Route('/payments/{payment}/test-complete', name: 'payment_test_complete', requirements: ['payment' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
    public function testComplete(Request $request, string $payment, #[CurrentUser] User $user): JsonResponse
    {
        $row = $this->own($payment, $user);
        // Вторая защита: даже тестовый платёж, созданный раньше, в production не подтвердить.
        if ('test' !== $row['provider'] || !$this->gateways->testMode()) {
            throw new NotFoundHttpException();
        }

        $data = $this->validator->validate($request, ['result' => 'required|in:succeeded,canceled']);

        if (PaymentService::PENDING !== $row['status']) {
            return new JsonResponse(['message' => 'Этот платёж уже завершён.'], 409);
        }

        $row = 'succeeded' === $data['result'] ? $this->payments->succeed($payment) : $this->payments->cancel($payment);

        return new JsonResponse(['data' => PaymentService::present($row) + ['balance' => $this->wallet->balance((int) $user->getId())]]);
    }

    /**
     * Чужой платёж — 404: незачем подтверждать, что такой есть.
     *
     * @return array<string, mixed>
     */
    private function own(string $id, User $user): array
    {
        $row = $this->payments->find($id);
        if (null === $row || (int) $row['user_id'] !== $user->getId()) {
            throw new NotFoundHttpException();
        }

        return $row;
    }
}
