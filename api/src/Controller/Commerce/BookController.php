<?php

declare(strict_types=1);

namespace App\Controller\Commerce;

use App\Api\Validation\InputValidator;
use App\Catalog\CatalogFilters;
use App\Catalog\RecipePresenter;
use App\Commerce\Access;
use App\Commerce\BookPdf;
use App\Commerce\Checkout;
use App\Entity\User;
use App\Security\Attribute\TrackVisit;
use App\Security\Attribute\VerifiedEmail;
use App\Support\Slug;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Книги: каталог, карточка, глава для читалки.
 * Покупка и PDF — с баланса, через Checkout.
 */
final class BookController
{
    public function __construct(
        private readonly Connection $db,
        private readonly InputValidator $validator,
        private readonly RecipePresenter $presenter,
        private readonly Access $access,
        private readonly Checkout $checkout,
        private readonly BookPdf $pdf,
    ) {
    }

    private function published(): QueryBuilder
    {
        return $this->db->createQueryBuilder()->select('b.*')->from('books', 'b')->where('b.is_published = 1');
    }

    /** Каталог книг: поиск, статус, цена (копейки), год, автор, страна, сортировка. */
    #[Route('/books', name: 'books_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $data = $this->validator->validate($request, [
            'q' => 'nullable|string|max:100',
            // filter — прежнее имя параметра, status — новое; значат одно и то же.
            'filter' => 'nullable|in:all,free,paid,subscription,mine',
            'status' => 'nullable|in:all,free,paid,subscription,mine',
            'price_min' => 'nullable|integer|min:0',
            'price_max' => 'nullable|integer|min:0',
            'year_from' => 'nullable|integer|between:1800,2100',
            'year_to' => 'nullable|integer|between:1800,2100',
            'author' => 'nullable|array|max:20',
            'author.*' => 'string|max:160',
            'country' => 'nullable|array|max:20',
            'country.*' => 'string|max:80',
            'sort' => 'nullable|in:new,price_asc,price_desc,title,year_desc,year_asc',
        ]);

        $qb = $this->published()
            ->addSelect('(SELECT COUNT(*) FROM book_chapters bc WHERE b.id = bc.book_id) AS chapters_count');

        match ($data['status'] ?? $data['filter'] ?? 'all') {
            'free' => $qb->andWhere('b.price = 0'),
            'paid' => $qb->andWhere('b.price > 0'),
            'subscription' => $qb->andWhere('b.in_subscription = 1'),
            'mine' => $qb->andWhere('EXISTS (SELECT 1 FROM book_purchases bp WHERE b.id = bp.book_id AND bp.user_id = :me)')
                ->setParameter('me', $user?->getId() ?? 0),
            default => null,
        };

        CatalogFilters::search($qb, 'b', $data['q'] ?? null);
        CatalogFilters::price($qb, 'b', $data['price_min'] ?? null, $data['price_max'] ?? null);
        CatalogFilters::in($qb, 'b', 'author_name', $data['author'] ?? null);
        CatalogFilters::in($qb, 'b', 'country', $data['country'] ?? null);
        if (isset($data['year_from'])) {
            $qb->andWhere('b.year >= :year_from')->setParameter('year_from', (int) $data['year_from']);
        }
        if (isset($data['year_to'])) {
            $qb->andWhere('b.year <= :year_to')->setParameter('year_to', (int) $data['year_to']);
        }

        match ($data['sort'] ?? 'new') {
            'price_asc' => $qb->orderBy('b.price', 'ASC'),
            'price_desc' => $qb->orderBy('b.price', 'DESC'),
            'title' => $qb->orderBy('b.title', 'ASC'),
            // Книги без года — в конце в обоих направлениях.
            'year_desc' => $qb->orderBy('b.year IS NULL')->addOrderBy('b.year', 'DESC'),
            'year_asc' => $qb->orderBy('b.year IS NULL')->addOrderBy('b.year', 'ASC'),
            default => $qb->orderBy('b.published_at', 'DESC'),
        };

        $rows = $qb->executeQuery()->fetchAllAssociative();
        $covers = $this->presenter->mediaByIds(array_column($rows, 'cover_media_id'));
        $all = $this->published();
        $years = (clone $all)->select('MIN(b.year) AS lo, MAX(b.year) AS hi')->executeQuery()->fetchAssociative();

        return new JsonResponse([
            'data' => array_map(fn (array $b): array => $this->card($b, $user, $covers), $rows),
            'meta' => [
                'total' => (int) (clone $all)->select('COUNT(*)')->executeQuery()->fetchOne(),
                'facets' => [
                    'authors' => CatalogFilters::facet($all, 'b', 'author_name'),
                    'countries' => CatalogFilters::facet($all, 'b', 'country'),
                    'years' => [
                        'min' => ((int) ($years['lo'] ?? 0)) ?: null,
                        'max' => ((int) ($years['hi'] ?? 0)) ?: null,
                    ],
                    'price' => CatalogFilters::priceRange($all, 'b'),
                ],
            ],
        ]);
    }

    #[Route('/books/{slug}', name: 'books_show', methods: ['GET'])]
    public function show(string $slug, #[CurrentUser] ?User $user): JsonResponse
    {
        $book = $this->findPublished($slug);
        $chapters = $this->chapters((int) $book['id']);
        $book['chapters_count'] = \count($chapters);

        return new JsonResponse(['data' => $this->card($book, $user, $this->presenter->mediaByIds([$book['cover_media_id']])) + [
            'description' => $book['description'],
            'chapters' => array_map(fn (array $c): array => [
                'position' => (int) $c['position'],
                'title' => $c['title'],
                'is_preview' => (bool) $c['is_preview'],
                'readable' => $this->access->canReadChapter($user, $book, $c),
            ], $chapters),
        ]]);
    }

    /** Глава для читалки. Закрытая — 403 с понятной причиной, а не 404. */
    #[Route('/books/{slug}/chapters/{position}', name: 'books_chapter', requirements: ['position' => Requirement::DIGITS], methods: ['GET'])]
    public function chapter(string $slug, int $position, #[CurrentUser] ?User $user): JsonResponse
    {
        $book = $this->findPublished($slug);
        $chapters = $this->chapters((int) $book['id']);
        $positions = array_map(static fn (array $c): int => (int) $c['position'], $chapters);
        $index = array_search($position, $positions, true);
        if (false === $index) {
            throw new NotFoundHttpException();
        }
        $chapter = $chapters[$index];

        if (!$this->access->canReadChapter($user, $book, $chapter)) {
            return new JsonResponse([
                'message' => (bool) $book['in_subscription']
                    ? 'Глава доступна после покупки книги или по подписке на закрытую академию.'
                    : 'Глава доступна после покупки книги.',
                'code' => 'locked',
            ], 403);
        }

        return new JsonResponse(['data' => [
            'book' => ['slug' => $book['slug'], 'title' => $book['title']],
            'position' => (int) $chapter['position'],
            'title' => $chapter['title'],
            'body' => $chapter['body'],
            'prev' => $index > 0 ? $positions[$index - 1] : null,
            'next' => $positions[$index + 1] ?? null,
        ]]);
    }

    #[Route('/books/{slug}/purchase', name: 'books_purchase', methods: ['POST'])]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
    public function purchase(string $slug, #[CurrentUser] User $user): JsonResponse
    {
        $book = $this->findPublished($slug);
        $this->checkout->buyBook((int) $user->getId(), $book);
        $book['chapters_count'] = (int) $this->db->fetchOne('SELECT COUNT(*) FROM book_chapters WHERE book_id = ?', [$book['id']]);

        return new JsonResponse(['data' => $this->card($book, $user, $this->presenter->mediaByIds([$book['cover_media_id']]))], 201);
    }

    /** PDF — только бесплатной или купленной книги; у платной — водяной знак с почтой покупателя. */
    #[Route('/books/{slug}/pdf', name: 'books_pdf', methods: ['GET'])]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
    public function pdf(string $slug, #[CurrentUser] User $user): Response
    {
        $book = $this->findPublished($slug);
        if (!$this->access->canDownload($user, $book)) {
            throw new AccessDeniedHttpException('Скачать можно бесплатную или купленную книгу.');
        }

        $name = (Slug::make((string) $book['title']) ?: 'book').'.pdf';

        return new Response($this->pdf->render($book, $this->chapters((int) $book['id']), $user->getEmail()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array<string, mixed> */
    private function findPublished(string $slug): array
    {
        return $this->published()->andWhere('b.slug = :slug')->setParameter('slug', $slug)
            ->setMaxResults(1)->executeQuery()->fetchAssociative() ?: throw new NotFoundHttpException();
    }

    /** @return list<array<string, mixed>> */
    private function chapters(int $bookId): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM book_chapters WHERE book_id = ? ORDER BY position ASC', [$bookId]);
    }

    /**
     * @param array<string, mixed>             $b
     * @param array<int, array<string, mixed>> $covers
     *
     * @return array<string, mixed>
     */
    private function card(array $b, ?User $user, array $covers): array
    {
        $cover = $covers[(int) $b['cover_media_id']] ?? null;

        return [
            'id' => (int) $b['id'],
            'slug' => $b['slug'],
            'title' => $b['title'],
            'author_name' => $b['author_name'],
            'year' => null === $b['year'] ? null : (int) $b['year'],
            'country' => $b['country'],
            'lead' => $b['lead'],
            'price' => (int) $b['price'],
            'is_free' => 0 === (int) $b['price'],
            'in_subscription' => (bool) $b['in_subscription'],
            'chapters_count' => (int) $b['chapters_count'],
            'cover' => null === $cover ? null : ['url' => $cover['url'], 'alt' => $cover['alt']],
            'owned' => $this->access->owns($user, $b),
            'can_read' => $this->access->canRead($user, $b),
            'can_download' => $this->access->canDownload($user, $b),
        ];
    }
}
