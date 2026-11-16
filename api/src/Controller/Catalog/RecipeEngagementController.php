<?php

declare(strict_types=1);

namespace App\Controller\Catalog;

use App\Api\Pagination\Paginator;
use App\Api\Validation\InputValidator;
use App\Catalog\PublishedRecipes;
use App\Catalog\RecipeQuery;
use App\Catalog\ReviewPresenter;
use App\Entity\User;
use App\Media\MediaStore;
use App\Rating\RatingService;
use App\Security\Attribute\TrackVisit;
use App\Security\Attribute\VerifiedEmail;
use App\Support\ActivityLog;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Отзывы, оценки, жалобы и просмотры рецепта: ReviewController и
 * RecipeEngagementController.
 */
final class RecipeEngagementController
{
    /** Причины — те же, что в форме на сайте (REPORT_REASONS). */
    public const REPORT_REASONS = [
        'error' => 'Ошибка в рецепте',
        'amounts' => 'Неверные граммовки или время',
        'copyright' => 'Нарушение авторских прав',
        'offensive' => 'Оскорбительное содержание',
        'spam' => 'Спам или реклама',
        'other' => 'Другое',
    ];

    public function __construct(
        private readonly RecipeQuery $recipes,
        private readonly ReviewPresenter $reviews,
        private readonly InputValidator $validator,
        private readonly MediaStore $media,
        private readonly RatingService $rating,
        private readonly ActivityLog $log,
        private readonly Connection $db,
        private readonly CacheInterface $cache,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Опубликованные отзывы, свежие первыми, по 10 — как ReviewController::index. */
    #[Route('/recipes/{slug}/reviews', name: 'recipes_reviews', methods: ['GET'])]
    public function reviews(Request $request, string $slug): JsonResponse
    {
        $recipe = $this->recipes->findPublishedBySlug($slug) ?? throw new NotFoundHttpException();

        $where = 'FROM reviews WHERE recipe_id = :recipe AND status = :status';
        $params = ['recipe' => $recipe['id'], 'status' => 'published'];
        $total = (int) $this->db->fetchOne("SELECT COUNT(*) {$where}", $params);

        $page = Paginator::currentPage($request);
        $rows = $this->db->fetchAllAssociative("SELECT * {$where} ORDER BY created_at DESC LIMIT 10 OFFSET ".(($page - 1) * 10), $params);

        return new JsonResponse(Paginator::page($request, $this->reviews->present($rows), $total, $page, 10));
    }

    #[Route('/recipes/{slug}/reviews', name: 'recipes_review_store', methods: ['POST'])]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
    public function storeReview(Request $request, string $slug, #[CurrentUser] User $user): JsonResponse
    {
        $recipe = $this->recipes->findPublishedBySlug($slug) ?? throw new NotFoundHttpException();

        if (false !== $this->db->fetchOne('SELECT 1 FROM reviews WHERE recipe_id = ? AND user_id = ?', [$recipe['id'], $user->getId()])) {
            return new JsonResponse(['message' => 'Вы уже оставляли отзыв к этому рецепту.', 'code' => 'review_exists'], 409);
        }

        $data = $this->validator->validate($request, [
            'rating' => 'required|integer|min:1|max:5',
            'body' => 'required|string|min:10|max:2000',
            'photos' => 'nullable|array|max:3',
            'photos.*' => 'image|mimes:jpeg,jpg,png,webp|max:8192',
        ], [
            'rating.required' => 'Поставьте оценку от 1 до 5.',
            'body.min' => 'Напишите пару предложений — так отзыв полезнее.',
            'photos.max' => 'К отзыву можно приложить не больше трёх снимков.',
            'photos.*.max' => 'Каждый снимок должен быть меньше 8 МБ.',
        ]);

        $reviewId = $this->db->transactional(function () use ($data, $recipe, $user): int {
            $now = PublishedRecipes::now($this->clock->now());
            $this->db->insert('reviews', [
                'recipe_id' => $recipe['id'], 'user_id' => $user->getId(),
                'rating' => (int) $data['rating'], 'body' => $data['body'], 'status' => 'published',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $reviewId = (int) $this->db->lastInsertId();

            $photos = array_values(\is_array($data['photos'] ?? null) ? $data['photos'] : []);
            foreach ($photos as $i => $file) {
                \assert($file instanceof UploadedFile);
                $media = $this->media->store($file, $user->getId(), $recipe['title'].' — фото читателя');
                $this->db->insert('review_media', ['review_id' => $reviewId, 'media_id' => $media['id'], 'position' => $i]);
            }

            $this->log->record($user->getId(), 'review.created', 'Review', $reviewId, ['recipe' => $recipe['slug']]);

            // Баллы за отзыв и, если приложены снимки, за фотоотчёт. Ключ — «человек + рецепт»,
            // поэтому удалить отзыв и написать заново ради баллов не выйдет.
            $this->rating->awardForReview((int) $user->getId(), $reviewId, (int) $recipe['id'], [] !== $photos);

            return $reviewId;
        });

        $review = $this->db->fetchAssociative('SELECT * FROM reviews WHERE id = ?', [$reviewId]);
        \assert(false !== $review);

        return new JsonResponse(['data' => $this->reviews->present([$review])[0]], 201);
    }

    #[Route('/reviews/{review}', name: 'reviews_destroy', requirements: ['review' => Requirement::DIGITS], methods: ['DELETE'])]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
    public function destroyReview(int $review, #[CurrentUser] User $user): JsonResponse
    {
        $row = $this->db->fetchAssociative('SELECT id, user_id FROM reviews WHERE id = ?', [$review]) ?: throw new NotFoundHttpException();

        // Свой отзыв можно удалить; чужие — только с правами редакции.
        if ((int) $row['user_id'] !== $user->getId() && !$user->isStaff()) {
            throw new AccessDeniedHttpException();
        }

        $this->db->delete('reviews', ['id' => $review]);

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    #[Route('/recipes/{id}/reaction', name: 'recipes_react', requirements: ['id' => Requirement::DIGITS], methods: ['PUT'])]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
    public function react(Request $request, int $id, #[CurrentUser] User $user): JsonResponse
    {
        $recipe = $this->recipes->findPublishedById($id) ?? throw new NotFoundHttpException();
        $data = $this->validator->validate($request, ['reaction' => 'required|in:like,dislike']);

        $this->db->transactional(function () use ($recipe, $user, $data): void {
            $now = PublishedRecipes::now($this->clock->now());
            // Вставка или обновление одним запросом: два одновременных нажатия не упадут на уникальном ключе.
            $this->db->executeStatement(
                'INSERT INTO recipe_reactions (recipe_id, user_id, reaction, created_at, updated_at) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE updated_at = IF(reaction = VALUES(reaction), updated_at, VALUES(updated_at)), reaction = VALUES(reaction)',
                [$recipe['id'], $user->getId(), $data['reaction'], $now, $now],
            );
            $this->recount((int) $recipe['id']);
        });

        return $this->reactionState((int) $recipe['id'], (string) $data['reaction']);
    }

    #[Route('/recipes/{id}/reaction', name: 'recipes_unreact', requirements: ['id' => Requirement::DIGITS], methods: ['DELETE'])]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
    public function unreact(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $recipe = $this->recipes->findPublishedById($id) ?? throw new NotFoundHttpException();

        $this->db->transactional(function () use ($recipe, $user): void {
            $this->db->delete('recipe_reactions', ['recipe_id' => $recipe['id'], 'user_id' => $user->getId()]);
            $this->recount((int) $recipe['id']);
        });

        return $this->reactionState((int) $recipe['id'], null);
    }

    /** Жалоба. Повторная от того же человека, пока первую не разобрали, обновляет её. */
    #[Route('/recipes/{slug}/reports', name: 'recipes_report', methods: ['POST'])]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
    public function report(Request $request, string $slug, #[CurrentUser] User $user): JsonResponse
    {
        $recipe = $this->recipes->findPublishedBySlug($slug) ?? throw new NotFoundHttpException();

        $other = 'other' === (InputValidator::input($request)['reason'] ?? null);
        $data = $this->validator->validate($request, [
            'reason' => 'required|in:'.implode(',', array_keys(self::REPORT_REASONS)),
            'message' => ($other ? 'required|' : '').'nullable|string|max:1000'.($other ? '|min:10' : ''),
        ], [
            'reason.required' => 'Выберите причину.',
            'reason.in' => 'Выберите причину из списка.',
            'message.required' => 'Опишите, что не так.',
            'message.min' => 'Опишите проблему хотя бы парой предложений.',
        ]);

        $now = PublishedRecipes::now($this->clock->now());
        $open = $this->db->fetchOne("SELECT id FROM recipe_reports WHERE recipe_id = ? AND user_id = ? AND status = 'open' LIMIT 1", [$recipe['id'], $user->getId()]);
        if (false === $open) {
            $this->db->insert('recipe_reports', [
                'recipe_id' => $recipe['id'], 'user_id' => $user->getId(), 'status' => 'open',
                'reason' => $data['reason'], 'message' => $data['message'] ?? null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $open = $this->db->lastInsertId();
        } else {
            $this->db->update('recipe_reports', ['reason' => $data['reason'], 'message' => $data['message'] ?? null, 'updated_at' => $now], ['id' => $open]);
        }

        $this->log->record($user->getId(), 'recipe.reported', 'Recipe', (int) $recipe['id'], ['title' => $recipe['title'], 'reason' => $data['reason']]);

        return new JsonResponse(['data' => ['id' => (int) $open, 'status' => 'open']], 201);
    }

    /**
     * Один просмотр в сутки на человека: для вошедшего — по аккаунту, для гостя —
     * по адресу и браузеру. cache->get() вычисляет значение один раз на ключ даже
     * при параллельных запросах (под блокировкой), так что двойной запрос не проскочит.
     */
    #[Route('/recipes/{slug}/view', name: 'recipes_view', methods: ['POST'])]
    public function view(Request $request, string $slug, #[CurrentUser] ?User $user): JsonResponse
    {
        $recipe = $this->recipes->findPublishedBySlug($slug) ?? throw new NotFoundHttpException();

        $viewer = null !== $user ? 'u'.$user->getId() : 'g'.sha1($request->getClientIp().'|'.$request->headers->get('User-Agent'));
        $today = $this->clock->now();
        $key = 'recipe-view.'.$recipe['id'].'.'.$viewer.'.'.$today->format('Y-m-d');

        $counted = false;
        $this->cache->get($key, static function (ItemInterface $item) use (&$counted, $today): bool {
            $item->expiresAt($today->setTime(23, 59, 59));
            $counted = true;

            return true;
        });

        if ($counted) {
            // increment() в Eloquent трогает и updated_at — как и здесь (от неё зависит lastmod в карте сайта).
            $this->db->executeStatement('UPDATE recipes SET views_count = views_count + 1, updated_at = ? WHERE id = ?', [
                PublishedRecipes::now($this->clock->now()), $recipe['id'],
            ]);
        }

        $views = (int) $this->db->fetchOne('SELECT views_count FROM recipes WHERE id = ?', [$recipe['id']]);

        return new JsonResponse(['data' => ['views_count' => $views]]);
    }

    /** Счётчики пересчитываются из таблицы оценок, а не ±1: так они не расходятся. */
    private function recount(int $recipeId): void
    {
        $counts = $this->db->fetchAllKeyValue('SELECT reaction, COUNT(*) FROM recipe_reactions WHERE recipe_id = ? GROUP BY reaction', [$recipeId]);
        $likes = (int) ($counts['like'] ?? 0);
        $dislikes = (int) ($counts['dislike'] ?? 0);

        // save() в Eloquent пишет только изменения, и updated_at — тоже лишь тогда.
        $this->db->executeStatement(
            'UPDATE recipes SET likes_count = ?, dislikes_count = ?, updated_at = ? WHERE id = ? AND (likes_count != ? OR dislikes_count != ?)',
            [$likes, $dislikes, PublishedRecipes::now($this->clock->now()), $recipeId, $likes, $dislikes],
        );
    }

    private function reactionState(int $recipeId, ?string $mine): JsonResponse
    {
        $row = $this->db->fetchAssociative('SELECT likes_count, dislikes_count FROM recipes WHERE id = ?', [$recipeId]);

        return new JsonResponse(['data' => [
            'likes_count' => (int) ($row['likes_count'] ?? 0),
            'dislikes_count' => (int) ($row['dislikes_count'] ?? 0),
            'my_reaction' => $mine,
        ]]);
    }
}
