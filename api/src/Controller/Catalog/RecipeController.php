<?php

declare(strict_types=1);

namespace App\Controller\Catalog;

use App\Api\Pagination\Paginator;
use App\Api\Validation\InputValidator;
use App\Catalog\RecipePresenter;
use App\Catalog\RecipeQuery;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Каталог и карточка рецепта. */
final class RecipeController
{
    public function __construct(
        private readonly RecipeQuery $recipes,
        private readonly RecipePresenter $presenter,
        private readonly InputValidator $validator,
        private readonly Connection $db,
    ) {
    }

    #[Route('/recipes', name: 'recipes_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $data = $this->validator->validate($request, [
            'q' => 'nullable|string|max:120',
            'category' => 'nullable|string|exists:categories,slug',
            // Каждый тег — отдельный подзапрос; без потолка тысяча тегов в адресе кладёт базу.
            'tags' => 'nullable|array|max:10',
            'tags.*' => 'string|max:60',
            'max_time' => 'nullable|integer|min:5|max:600',
            'difficulty' => 'nullable|array',
            'difficulty.*' => 'string|in:easy,medium,hard',
            'diet' => 'nullable|array',
            'diet.*' => 'string|in:vegetarian,gluten_free',
            'sort' => 'nullable|in:popular,new,fast',
            'per_page' => 'nullable|integer|min:1|max:48',
        ]);

        $qb = $this->recipes->published();
        $this->recipes->withReviewsCount($qb);
        $this->recipes->withReviewsAvg($qb);

        if (!empty($data['q'])) {
            $qb->andWhere('MATCH (r.title, r.lead) AGAINST (:q IN NATURAL LANGUAGE MODE)')->setParameter('q', $data['q']);
        }

        if (!empty($data['category'])) {
            $qb->andWhere('EXISTS (SELECT 1 FROM categories c WHERE r.category_id = c.id AND c.slug = :category)')
                ->setParameter('category', $data['category']);
        }

        // Рецепт должен нести все перечисленные теги, а не любой из них.
        foreach (array_values($data['tags'] ?? []) as $i => $tag) {
            $qb->andWhere("EXISTS (SELECT 1 FROM tags t INNER JOIN recipe_tag rt ON t.id = rt.tag_id WHERE r.id = rt.recipe_id AND t.slug = :tag{$i})")
                ->setParameter("tag{$i}", $tag);
        }

        if (!empty($data['max_time'])) {
            $qb->andWhere('r.time_minutes <= :max_time')->setParameter('max_time', (int) $data['max_time']);
        }

        if (!empty($data['difficulty'])) {
            $qb->andWhere('r.difficulty IN (:difficulty)')
                ->setParameter('difficulty', array_values($data['difficulty']), \Doctrine\DBAL\ArrayParameterType::STRING);
        }

        foreach ($data['diet'] ?? [] as $diet) {
            $qb->andWhere('vegetarian' === $diet ? 'r.is_vegetarian = 1' : 'r.is_gluten_free = 1');
        }

        match ($data['sort'] ?? 'popular') {
            'new' => $qb->orderBy('r.published_at', 'DESC'),
            'fast' => $qb->orderBy('r.time_minutes', 'ASC'),
            default => $qb->orderBy('reviews_count', 'DESC')->addOrderBy('r.published_at', 'DESC'),
        };

        $perPage = (int) ($data['per_page'] ?? 12);
        $page = Paginator::currentPage($request);
        $result = $this->recipes->paginate($qb, $page, $perPage);

        return new JsonResponse(Paginator::page(
            $request,
            $this->presenter->cards($result['rows'], [RecipePresenter::COVER, RecipePresenter::CATEGORY, RecipePresenter::TAGS]),
            $result['total'],
            $page,
            $perPage,
        ));
    }

    #[Route('/recipes/{slug}', name: 'recipes_show', methods: ['GET'])]
    public function show(string $slug, #[CurrentUser] ?User $user): JsonResponse
    {
        $recipe = $this->recipes->findPublishedBySlug($slug, full: true) ?? throw new NotFoundHttpException();

        $personal = null;
        if (null !== $user) {
            $params = ['recipe' => $recipe['id'], 'user' => $user->getId()];
            $personal = [
                'is_favorite' => false !== $this->db->fetchOne('SELECT 1 FROM favorites WHERE recipe_id = :recipe AND user_id = :user', $params),
                'in_cookbook' => false !== $this->db->fetchOne('SELECT 1 FROM cookbook_recipes WHERE recipe_id = :recipe AND user_id = :user', $params),
                'my_reaction' => $this->db->fetchOne('SELECT reaction FROM recipe_reactions WHERE recipe_id = :recipe AND user_id = :user', $params) ?: null,
            ];
        }

        return new JsonResponse(['data' => $this->presenter->full($recipe, $personal)]);
    }

    /** Похожие: сначала из той же категории, затем по общим тегам. */
    #[Route('/recipes/{slug}/related', name: 'recipes_related', methods: ['GET'])]
    public function related(string $slug): JsonResponse
    {
        $recipe = $this->recipes->findPublishedBySlug($slug) ?? throw new NotFoundHttpException();

        return new JsonResponse(['data' => $this->presenter->cards($this->recipes->related($recipe))]);
    }
}
