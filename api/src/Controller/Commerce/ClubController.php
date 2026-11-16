<?php

declare(strict_types=1);

namespace App\Controller\Commerce;

use App\Api\Validation\InputValidator;
use App\Catalog\RecipePresenter;
use App\Commerce\Access;
use App\Commerce\Checkout;
use App\Entity\User;
use App\Media\PrivateFiles;
use App\Repository\UserRepository;
use App\Security\Attribute\TrackVisit;
use App\Security\Attribute\VerifiedEmail;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Закрытая академия. Каталог и тарифы видны всем,
 * чтобы было понятно, за что платить. Содержимое, видео и книги из подписки —
 * только подписчикам. Подписка оплачивается с баланса, через Checkout.
 */
final class ClubController
{
    public const KINDS = [
        'video' => 'Видеоурок',
        'masterclass' => 'Закрытый мастер-класс',
        'recipe' => 'Ресторанный рецепт',
        'material' => 'Материал',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly InputValidator $validator,
        private readonly RecipePresenter $presenter,
        private readonly Access $access,
        private readonly PrivateFiles $files,
        private readonly UriSigner $signer,
        private readonly UrlGeneratorInterface $urls,
        private readonly UserRepository $users,
        private readonly ClockInterface $clock,
        private readonly Checkout $checkout,
    ) {
    }

    #[Route('/club', name: 'club_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $data = $this->validator->validate($request, ['kind' => 'nullable|in:'.implode(',', array_keys(self::KINDS))]);
        $unlocked = $this->access->hasSubscription($user);

        $sql = 'SELECT * FROM club_items WHERE is_published = 1';
        $params = [];
        if (!empty($data['kind'])) {
            $sql .= ' AND kind = ?';
            $params[] = $data['kind'];
        }
        $items = $this->db->fetchAllAssociative($sql.' ORDER BY published_at DESC', $params);
        $books = $this->db->fetchAllAssociative('SELECT * FROM books WHERE is_published = 1 AND in_subscription = 1 ORDER BY title ASC');
        $covers = $this->presenter->mediaByIds(array_merge(array_column($items, 'cover_media_id'), array_column($books, 'cover_media_id')));
        $plans = $this->db->fetchAllAssociative('SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY position ASC');

        return new JsonResponse([
            'data' => array_map(fn (array $i): array => $this->card($i, $unlocked, $covers), $items),
            'meta' => [
                'kinds' => array_map(static fn (string $code, string $label): array => ['code' => $code, 'label' => $label], array_keys(self::KINDS), self::KINDS),
                'plans' => array_map(static fn (array $p): array => [
                    'code' => $p['code'],
                    'title' => $p['title'],
                    'months' => (int) $p['months'],
                    'price' => (int) $p['price'],
                    // Цена месяца — чтобы показать выгоду годового плана. До рубля: «658,33 ₽» читается хуже.
                    'per_month' => (int) round((int) $p['price'] / max(1, (int) $p['months']) / 100) * 100,
                ], $plans),
                'subscription' => $this->access->subscriptionState($user),
                'books' => array_map(static fn (array $b): array => [
                    'slug' => $b['slug'],
                    'title' => $b['title'],
                    'author_name' => $b['author_name'],
                    'cover' => isset($covers[(int) $b['cover_media_id']]) ? ['url' => $covers[(int) $b['cover_media_id']]['url'], 'alt' => $covers[(int) $b['cover_media_id']]['alt']] : null,
                ], $books),
            ],
        ]);
    }

    #[Route('/club/{slug}', name: 'club_show', methods: ['GET'])]
    public function show(Request $request, string $slug, #[CurrentUser] ?User $user): JsonResponse
    {
        $item = $this->db->fetchAssociative('SELECT * FROM club_items WHERE is_published = 1 AND slug = ? LIMIT 1', [$slug]) ?: throw new NotFoundHttpException();
        $unlocked = $this->access->hasSubscription($user);
        $card = $this->card($item, $unlocked, $this->presenter->mediaByIds([$item['cover_media_id']]));
        $meta = ['subscription' => $this->access->subscriptionState($user)];

        // Без подписки — только обложка и описание: тело, видео и рецепт не отдаём.
        if (!$unlocked || null === $user) {
            return new JsonResponse(['data' => $card, 'meta' => $meta]);
        }

        return new JsonResponse(['data' => $card + [
            'body' => $item['body'],
            'recipe' => 'recipe' === $item['kind'] && null !== $item['data'] ? json_decode((string) $item['data'], true) : null,
            // Ссылка на видео живёт 2 часа: ею нельзя поделиться надолго.
            'video_url' => self::hasVideo($item) ? $this->signer->sign(
                $this->urls->generate('club_video', ['item' => $item['id'], 'user' => $user->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                $this->clock->now()->modify('+2 hours'),
            ) : null,
        ], 'meta' => $meta]);
    }

    /**
     * Видео по подписанной ссылке. Подпись и срок проверяет UriSigner, а подписку —
     * ещё раз здесь: ссылка не должна пережить конец подписки.
     */
    #[Route('/club-video/{item}', name: 'club_video', requirements: ['item' => Requirement::DIGITS], methods: ['GET'])]
    public function video(Request $request, int $item): BinaryFileResponse
    {
        if (!$this->signer->checkRequest($request)) {
            throw new AccessDeniedHttpException('Invalid signature.');
        }

        $row = $this->db->fetchAssociative('SELECT * FROM club_items WHERE id = ?', [$item]) ?: throw new NotFoundHttpException();
        $viewer = $this->users->find((int) $request->query->get('user'));
        if (!self::hasVideo($row) || !$this->access->hasSubscription($viewer)) {
            throw new AccessDeniedHttpException();
        }

        $path = $this->files->path((string) $row['video_disk'], (string) $row['video_path']) ?? throw new NotFoundHttpException();

        // BinaryFileResponse отвечает на Range-запросы — видео можно перематывать.
        return new BinaryFileResponse($path, 200, ['Content-Type' => 'video/mp4', 'Cache-Control' => 'private, max-age=0']);
    }

    #[Route('/club/subscribe', name: 'club_subscribe', methods: ['POST'], priority: 1)]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
    public function subscribe(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = $this->validator->validate($request, ['plan' => 'required|string']);
        $plan = $this->db->fetchAssociative('SELECT * FROM subscription_plans WHERE code = ? AND is_active = 1 LIMIT 1', [$data['plan']])
            ?: throw new NotFoundHttpException();

        $this->checkout->subscribe((int) $user->getId(), $plan);

        return new JsonResponse(['data' => $this->access->subscriptionState($user)], 201);
    }

    #[Route('/me/subscription', name: 'club_my_subscription', methods: ['GET'])]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
    public function mySubscription(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(['data' => $this->access->subscriptionState($user)]);
    }

    /** @param array<string, mixed> $item */
    private static function hasVideo(array $item): bool
    {
        return null !== $item['video_path'] && null !== $item['video_disk'];
    }

    /**
     * ClubItem::toCard().
     *
     * @param array<string, mixed>             $i
     * @param array<int, array<string, mixed>> $covers
     *
     * @return array<string, mixed>
     */
    private function card(array $i, bool $unlocked, array $covers): array
    {
        $cover = $covers[(int) $i['cover_media_id']] ?? null;

        return [
            'slug' => $i['slug'],
            'kind' => $i['kind'],
            'kind_label' => self::KINDS[$i['kind']] ?? $i['kind'],
            'title' => $i['title'],
            'lead' => $i['lead'],
            'chef_name' => $i['chef_name'],
            'duration_minutes' => null === $i['duration_minutes'] ? null : (int) $i['duration_minutes'],
            'has_video' => self::hasVideo($i),
            'cover' => null === $cover ? null : ['url' => $cover['url'], 'alt' => $cover['alt']],
            'unlocked' => $unlocked,
        ];
    }
}
