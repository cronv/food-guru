<?php

declare(strict_types=1);

namespace App\Controller\Catalog;

use App\Api\Pagination\Paginator;
use App\Catalog\HomeFeed;
use App\Catalog\RecipePresenter;
use App\Catalog\RecipeQuery;
use App\Entity\Category;
use App\Repository\CategoryRepository;
use App\Repository\TagRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Справочники, подборки, ролики и главная. Ответы повторяют прежний формат поле в поле. */
final class CatalogController
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly TagRepository $tags,
        private readonly RecipeQuery $recipes,
        private readonly RecipePresenter $presenter,
        private readonly HomeFeed $feed,
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/categories', name: 'catalog_categories', methods: ['GET'])]
    public function categories(): JsonResponse
    {
        $counts = $this->categories->publishedRecipeCounts($this->clock->now());

        return new JsonResponse([
            'data' => array_map(static fn (Category $c): array => [
                'id' => $c->getId(),
                'slug' => $c->getSlug(),
                'title' => $c->getTitle(),
                'description' => $c->getDescription(),
                'recipes_count' => $counts[(int) $c->getId()] ?? 0,
                'children' => array_values(array_map(static fn (Category $ch): array => [
                    'id' => $ch->getId(),
                    'slug' => $ch->getSlug(),
                    'title' => $ch->getTitle(),
                ], $c->getChildren()->toArray())),
            ], $this->categories->findRootsWithChildren()),
        ]);
    }

    #[Route('/categories/{slug}', name: 'catalog_category', methods: ['GET'])]
    public function category(Request $request, string $slug): JsonResponse
    {
        $category = $this->categories->findOneBy(['slug' => $slug]) ?? throw new NotFoundHttpException();

        $qb = $this->recipes->published()
            ->andWhere('r.category_id = :category')->setParameter('category', $category->getId())
            ->orderBy('r.published_at', 'DESC');
        $this->recipes->withReviewsCount($qb);

        $page = Paginator::currentPage($request);
        $result = $this->recipes->paginate($qb, $page, 12);

        return new JsonResponse([
            'data' => [
                'id' => $category->getId(),
                'slug' => $category->getSlug(),
                'title' => $category->getTitle(),
                'description' => $category->getDescription(),
            ],
            'recipes' => Paginator::page($request, $this->presenter->cards($result['rows']), $result['total'], $page, 12),
        ]);
    }

    #[Route('/tags', name: 'catalog_tags', methods: ['GET'])]
    public function tags(): JsonResponse
    {
        return new JsonResponse(['data' => $this->tags->withPublishedRecipeCounts($this->clock->now())]);
    }

    #[Route('/collections', name: 'catalog_collections', methods: ['GET'])]
    public function collections(): JsonResponse
    {
        // withCount('recipes') — все рецепты подборки, без фильтра по публикации.
        $rows = $this->db->fetchAllAssociative(
            'SELECT col.*, (SELECT COUNT(*) FROM recipes r INNER JOIN collection_recipe cr ON r.id = cr.recipe_id WHERE col.id = cr.collection_id) AS recipes_count
               FROM collections col WHERE col.is_published = 1 ORDER BY col.id DESC',
        );
        $covers = $this->presenter->mediaByIds(array_column($rows, 'cover_media_id'));

        return new JsonResponse(['data' => array_map(static fn (array $c): array => [
            'slug' => $c['slug'],
            'title' => $c['title'],
            'lead' => $c['lead'],
            'recipes_count' => (int) $c['recipes_count'],
            'cover' => $covers[(int) $c['cover_media_id']]['url'] ?? null,
        ], $rows)]);
    }

    #[Route('/collections/{slug}', name: 'catalog_collection', methods: ['GET'])]
    public function collection(string $slug): JsonResponse
    {
        $collection = $this->db->fetchAssociative('SELECT * FROM collections WHERE slug = ? AND is_published = 1 LIMIT 1', [$slug])
            ?: throw new NotFoundHttpException();

        $recipes = $this->recipes->published()
            ->innerJoin('r', 'collection_recipe', 'cr', 'r.id = cr.recipe_id')
            ->andWhere('cr.collection_id = :collection')->setParameter('collection', $collection['id'])
            ->orderBy('cr.position', 'ASC')
            ->executeQuery()->fetchAllAssociative();

        $cover = $collection['cover_media_id'] ? ($this->presenter->mediaByIds([$collection['cover_media_id']])[(int) $collection['cover_media_id']] ?? null) : null;

        return new JsonResponse(['data' => [
            'slug' => $collection['slug'],
            'title' => $collection['title'],
            'lead' => $collection['lead'],
            'cover' => $cover['url'] ?? null,
            'recipes' => $this->presenter->cards($recipes),
        ]]);
    }

    /** Лента видеорецептов: опубликованные рецепты, у которых есть ролик. */
    #[Route('/reels', name: 'catalog_reels', methods: ['GET'])]
    public function reels(Request $request): JsonResponse
    {
        $qb = $this->recipes->published()->andWhere('r.reel_path IS NOT NULL')->orderBy('r.published_at', 'DESC');
        $page = Paginator::currentPage($request);
        $result = $this->recipes->paginate($qb, $page, 12);

        return new JsonResponse(Paginator::page($request, $this->presenter->cards($result['rows']), $result['total'], $page, 12));
    }

    /** Данные главной страницы одним запросом — чтобы SSR не делал восемь. */
    #[Route('/home', name: 'catalog_home', methods: ['GET'])]
    public function home(): JsonResponse
    {
        $hero = $this->recipes->published()->orderBy('r.published_at', 'DESC')->setMaxResults(1)->executeQuery()->fetchAllAssociative();
        $reels = $this->recipes->published()->andWhere('r.reel_path IS NOT NULL')
            ->orderBy('r.published_at', 'DESC')->setMaxResults(6)->executeQuery()->fetchAllAssociative();

        $roots = $this->db->fetchAllAssociative('SELECT slug, title FROM categories WHERE parent_id IS NULL ORDER BY position ASC');

        return new JsonResponse(['data' => [
            'hero' => $this->presenter->cards($hero)[0] ?? null,
            'popular' => $this->feed->popularThisWeek(8),
            'reels' => $this->presenter->cards($reels),
            'collections' => $this->feed->popularCollections(6),
            'best' => $this->feed->best(6),
            'most_saved' => $this->feed->mostSaved(6),
            'top_cooks' => $this->feed->topCooks(6),
            'categories' => array_map(static fn (array $c): array => ['slug' => $c['slug'], 'title' => $c['title']], $roots),
        ]]);
    }
}
