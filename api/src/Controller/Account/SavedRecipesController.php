<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Api\Pagination\Paginator;
use App\Api\Validation\InputValidator;
use App\Api\Validation\ValidationFailed;
use App\Catalog\PublishedRecipes;
use App\Catalog\RecipePresenter;
use App\Catalog\RecipeQuery;
use App\Entity\User;
use App\Security\Attribute\TrackVisit;
use App\Security\Attribute\VerifiedEmail;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Избранное и рецептосохранялка (рецепты сайта и свои заметки) — как
 * FavoriteController и CookbookController. Это два разных списка.
 */
#[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
final class SavedRecipesController
{
    /** Предел заметок на человека: книга, а не хранилище файлов. */
    private const NOTES_LIMIT = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly RecipeQuery $recipes,
        private readonly RecipePresenter $presenter,
        private readonly InputValidator $validator,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/me/favorites', name: 'me_favorites', methods: ['GET'])]
    public function favorites(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse($this->savedPage($request, 'favorites', $user));
    }

    #[Route('/recipes/{id}/favorite', name: 'favorite_store', requirements: ['id' => Requirement::DIGITS], methods: ['PUT'])]
    public function favorite(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->save('favorites', $this->publishedRecipe($id), $user);

        return new JsonResponse(['data' => ['favorite' => true]]);
    }

    #[Route('/recipes/{id}/favorite', name: 'favorite_destroy', requirements: ['id' => Requirement::DIGITS], methods: ['DELETE'])]
    public function unfavorite(int $id, #[CurrentUser] User $user): JsonResponse
    {
        // Рецепт находится по id до действия — несуществующий даёт 404.
        if (false === $this->db->fetchOne('SELECT 1 FROM recipes WHERE id = ?', [$id])) {
            throw new NotFoundHttpException();
        }
        $this->db->delete('favorites', ['user_id' => $user->getId(), 'recipe_id' => $id]);

        return new JsonResponse(['data' => ['favorite' => false]]);
    }

    #[Route('/me/cookbook/recipes', name: 'cookbook_recipes', methods: ['GET'])]
    public function cookbookRecipes(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse($this->savedPage($request, 'cookbook_recipes', $user));
    }

    #[Route('/recipes/{id}/cookbook', name: 'cookbook_save', requirements: ['id' => Requirement::DIGITS], methods: ['PUT'])]
    public function saveToCookbook(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $this->save('cookbook_recipes', $this->publishedRecipe($id), $user);

        return $this->cookbookState($id, $user);
    }

    #[Route('/recipes/{id}/cookbook', name: 'cookbook_forget', requirements: ['id' => Requirement::DIGITS], methods: ['DELETE'])]
    public function forgetFromCookbook(int $id, #[CurrentUser] User $user): JsonResponse
    {
        // Рецепт находится по id до действия — несуществующий даёт 404.
        if (false === $this->db->fetchOne('SELECT 1 FROM recipes WHERE id = ?', [$id])) {
            throw new NotFoundHttpException();
        }
        $this->db->delete('cookbook_recipes', ['user_id' => $user->getId(), 'recipe_id' => $id]);

        return $this->cookbookState($id, $user);
    }

    #[Route('/me/cookbook/notes', name: 'cookbook_notes', methods: ['GET'])]
    public function notes(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $page = Paginator::currentPage($request);
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM cookbook_notes WHERE user_id = ?', [$user->getId()]);
        $rows = $this->db->fetchAllAssociative('SELECT * FROM cookbook_notes WHERE user_id = ? ORDER BY updated_at DESC LIMIT 20 OFFSET '.(($page - 1) * 20), [$user->getId()]);

        return new JsonResponse([
            'data' => array_map(self::note(...), $rows),
            'meta' => ['current_page' => $page, 'last_page' => max((int) ceil($total / 20), 1), 'per_page' => 20, 'total' => $total],
        ]);
    }

    #[Route('/me/cookbook/notes', name: 'cookbook_note_store', methods: ['POST'])]
    public function storeNote(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM cookbook_notes WHERE user_id = ?', [$user->getId()]) >= self::NOTES_LIMIT) {
            return new JsonResponse(['message' => 'В рецептосохранялке уже '.self::NOTES_LIMIT.' заметок — удалите ненужные.'], 422);
        }

        $data = $this->noteInput($request);
        $now = PublishedRecipes::now($this->clock->now());
        $this->db->insert('cookbook_notes', $data + ['user_id' => $user->getId(), 'created_at' => $now, 'updated_at' => $now]);

        return new JsonResponse(['data' => self::note($this->findNote((int) $this->db->lastInsertId(), $user))], 201);
    }

    #[Route('/me/cookbook/notes/{note}', name: 'cookbook_note_update', requirements: ['note' => Requirement::DIGITS], methods: ['PATCH'])]
    public function updateNote(Request $request, int $note, #[CurrentUser] User $user): JsonResponse
    {
        $this->findNote($note, $user);
        $this->db->update('cookbook_notes', $this->noteInput($request) + ['updated_at' => PublishedRecipes::now($this->clock->now())], ['id' => $note]);

        return new JsonResponse(['data' => self::note($this->findNote($note, $user))]);
    }

    #[Route('/me/cookbook/notes/{note}', name: 'cookbook_note_destroy', requirements: ['note' => Requirement::DIGITS], methods: ['DELETE'])]
    public function destroyNote(int $note, #[CurrentUser] User $user): JsonResponse
    {
        $this->findNote($note, $user);
        $this->db->delete('cookbook_notes', ['id' => $note]);

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /** @return array<string, mixed> */
    private function publishedRecipe(int $id): array
    {
        return $this->recipes->findPublishedById($id) ?? throw new NotFoundHttpException();
    }

    /**
     * syncWithoutDetaching с датой: новое — вставить, уже сохранённое — обновить
     * дату, и рецепт поднимается наверх списка. Повторное нажатие не падает на ключе.
     *
     * @param array<string, mixed> $recipe
     */
    private function save(string $table, array $recipe, User $user): void
    {
        $this->db->executeStatement(
            "INSERT INTO {$table} (user_id, recipe_id, created_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE created_at = VALUES(created_at)",
            [$user->getId(), $recipe['id'], PublishedRecipes::now($this->clock->now())],
        );
    }

    /**
     * Сохранённые опубликованные рецепты, последние сохранённые первыми, по 12.
     *
     * @return array<string, mixed>
     */
    private function savedPage(Request $request, string $table, User $user): array
    {
        $qb = $this->recipes->published()
            ->innerJoin('r', $table, 's', 'r.id = s.recipe_id')
            ->andWhere('s.user_id = :me')->setParameter('me', $user->getId())
            ->orderBy('s.created_at', 'DESC');

        $page = Paginator::currentPage($request);
        $result = $this->recipes->paginate($qb, $page, 12);

        return Paginator::page($request, $this->presenter->cards($result['rows']), $result['total'], $page, 12);
    }

    private function cookbookState(int $recipeId, User $user): JsonResponse
    {
        return new JsonResponse(['data' => [
            'in_cookbook' => false !== $this->db->fetchOne('SELECT 1 FROM cookbook_recipes WHERE user_id = ? AND recipe_id = ?', [$user->getId(), $recipeId]),
            'saves_count' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM users u INNER JOIN cookbook_recipes c ON u.id = c.user_id WHERE c.recipe_id = ?', [$recipeId]),
        ]]);
    }

    /**
     * Чужая заметка — 404, а не 403: незачем подтверждать, что такая запись есть.
     *
     * @return array<string, mixed>
     */
    private function findNote(int $id, User $user): array
    {
        $note = $this->db->fetchAssociative('SELECT * FROM cookbook_notes WHERE id = ?', [$id]);
        if (false === $note || (int) $note['user_id'] !== $user->getId()) {
            throw new NotFoundHttpException();
        }

        return $note;
    }

    /** @return array<string, mixed> только title, url, body — что прислали */
    private function noteInput(Request $request): array
    {
        $data = $this->validator->validate($request, [
            'title' => 'required|string|min:2|max:200',
            // Только http(s): javascript: и data: в ссылке превратили бы заметку в ловушку.
            'url' => 'nullable|string|max:2048|url:http,https',
            'body' => 'nullable|string|max:20000',
        ], [
            'title.required' => 'Назовите рецепт.',
            'url.url' => 'Ссылка должна начинаться с http:// или https://.',
        ]);

        if (empty($data['url']) && empty($data['body'])) {
            throw ValidationFailed::field('body', 'Добавьте ссылку на рецепт или запишите его текст.');
        }

        /* @var array{title: mixed, url?: mixed, body?: mixed} */
        return array_intersect_key($data, array_flip(['title', 'url', 'body']));
    }

    /**
     * @param array<string, mixed> $n
     *
     * @return array<string, mixed>
     */
    private static function note(array $n): array
    {
        return [
            'id' => (int) $n['id'],
            'title' => $n['title'],
            'url' => $n['url'],
            'body' => $n['body'],
            'created_at' => RecipePresenter::iso($n['created_at']),
            'updated_at' => RecipePresenter::iso($n['updated_at']),
        ];
    }
}
