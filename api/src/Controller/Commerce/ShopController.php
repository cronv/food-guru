<?php

declare(strict_types=1);

namespace App\Controller\Commerce;

use App\Api\Pagination\Paginator;
use App\Api\Validation\InputValidator;
use App\Catalog\CatalogFilters;
use App\Catalog\RecipePresenter;
use App\Commerce\Checkout;
use App\Commerce\Delivery;
use App\Commerce\Orders;
use App\Entity\User;
use App\Security\Attribute\RouteThrottle;
use App\Security\Attribute\TrackVisit;
use App\Security\Attribute\VerifiedEmail;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Магазин: каталог, карточка, способы доставки.
 * Заказ оплачивается с баланса, через Checkout.
 */
final class ShopController
{
    public function __construct(
        private readonly Connection $db,
        private readonly InputValidator $validator,
        private readonly RecipePresenter $presenter,
        private readonly Checkout $checkout,
        private readonly Orders $orders,
    ) {
    }

    private function published(): QueryBuilder
    {
        return $this->db->createQueryBuilder()->select('p.*')->from('products', 'p')->where('p.is_published = 1');
    }

    /**
     * Каталог с фильтрами. Списки (категория, материал, страна, цвет) — по нескольку
     * значений: category[]=Ножи&category[]=Доски. Цена — в копейках.
     */
    #[Route('/shop/products', name: 'shop_products', methods: ['GET'])]
    public function products(Request $request): JsonResponse
    {
        $data = $this->validator->validate($request, [
            'q' => 'nullable|string|max:100',
            'category' => 'nullable|array|max:20', 'category.*' => 'string|max:80',
            'material' => 'nullable|array|max:20', 'material.*' => 'string|max:80',
            'country' => 'nullable|array|max:20', 'country.*' => 'string|max:80',
            'color' => 'nullable|array|max:20', 'color.*' => 'string|max:40',
            'price_min' => 'nullable|integer|min:0',
            'price_max' => 'nullable|integer|min:0',
            'in_stock' => 'nullable|boolean',
            'sort' => 'nullable|in:popular,price_asc,price_desc,new,title,discount',
        ]);

        $qb = $this->published();
        CatalogFilters::search($qb, 'p', $data['q'] ?? null);
        foreach (['category', 'material', 'country', 'color'] as $field) {
            CatalogFilters::in($qb, 'p', $field, $data[$field] ?? null);
        }
        CatalogFilters::price($qb, 'p', $data['price_min'] ?? null, $data['price_max'] ?? null);

        // Булево значение из запроса: 1, true, on, yes.
        if (filter_var($request->query->get('in_stock'), \FILTER_VALIDATE_BOOL)) {
            $qb->andWhere('p.stock > 0');
        }

        // Отсутствующее — всегда в конце, какой бы ни была сортировка.
        $qb->orderBy('p.stock > 0', 'DESC');
        match ($data['sort'] ?? 'popular') {
            'price_asc' => $qb->addOrderBy('p.price', 'ASC'),
            'price_desc' => $qb->addOrderBy('p.price', 'DESC'),
            'new' => $qb->addOrderBy('p.created_at', 'DESC'),
            'title' => $qb->addOrderBy('p.title', 'ASC'),
            'discount' => $qb->addOrderBy('CASE WHEN p.old_price IS NULL THEN 0 ELSE (p.old_price - p.price) * 1.0 / p.old_price END', 'DESC'),
            // «Популярные»: пока продаж мало — сперва со скидкой, потом по названию.
            default => $qb->addOrderBy('p.old_price IS NULL')->addOrderBy('p.title', 'ASC'),
        };

        $rows = $qb->executeQuery()->fetchAllAssociative();
        $covers = $this->presenter->mediaByIds(array_column($rows, 'cover_media_id'));
        $all = $this->published();
        $categories = CatalogFilters::facet($all, 'p', 'category');

        return new JsonResponse([
            'data' => array_map(fn (array $p): array => $this->present($p, $covers), $rows),
            'meta' => [
                'total' => (int) (clone $all)->select('COUNT(*)')->executeQuery()->fetchOne(),
                'facets' => [
                    'categories' => $categories,
                    'materials' => CatalogFilters::facet($all, 'p', 'material'),
                    'countries' => CatalogFilters::facet($all, 'p', 'country'),
                    'colors' => CatalogFilters::facet($all, 'p', 'color'),
                    'price' => CatalogFilters::priceRange($all, 'p'),
                ],
                // Старое поле — для совместимости с уже открытыми вкладками.
                'categories' => $categories,
            ],
        ]);
    }

    #[Route('/shop/products/{slug}', name: 'shop_product', methods: ['GET'])]
    public function product(string $slug): JsonResponse
    {
        $product = $this->published()->andWhere('p.slug = :slug')->setParameter('slug', $slug)
            ->setMaxResults(1)->executeQuery()->fetchAssociative() ?: throw new NotFoundHttpException();

        return new JsonResponse(['data' => $this->present($product, $this->presenter->mediaByIds([$product['cover_media_id']]), full: true)]);
    }

    /** Способы доставки с ценами — корзина считает итог по ним же. */
    #[Route('/shop/delivery', name: 'shop_delivery', methods: ['GET'])]
    public function delivery(): JsonResponse
    {
        return new JsonResponse(['data' => array_map(
            static fn (string $code, array $d): array => ['code' => $code] + $d,
            array_keys(Delivery::METHODS),
            Delivery::METHODS,
        )]);
    }

    /** Оформить заказ. Цены берутся из базы: присланные корзиной суммы не используются. */
    #[Route('/shop/orders', name: 'shop_place_order', methods: ['POST'])]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit, RouteThrottle(limit: 20, minutes: 1)]
    public function placeOrder(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $input = InputValidator::input($request);
        $needsAddress = (bool) (Delivery::METHODS[(string) ($input['delivery_method'] ?? '')]['needs_address'] ?? false);
        $addressRule = $needsAddress ? 'required' : 'nullable';

        $data = $this->validator->validate($request, [
            'items' => 'required|array|min:1|max:50',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1|max:99',
            'delivery_method' => 'required|in:'.implode(',', array_keys(Delivery::METHODS)),
            'recipient_name' => 'required|string|min:2|max:160',
            'phone' => 'required|string|regex:/^\+?[\d\s\-()]{10,20}$/',
            'city' => $addressRule.'|string|max:120',
            'address' => $addressRule.'|string|max:400',
            'comment' => 'nullable|string|max:1000',
        ], [
            'items.required' => 'Корзина пуста.',
            'recipient_name.required' => 'Укажите, кто получит заказ.',
            'phone.required' => 'Укажите телефон для связи.',
            'phone.regex' => 'Телефон выглядит неверно — проверьте цифры.',
            'city.required' => 'Укажите город.',
            'address.required' => 'Укажите адрес доставки.',
        ], $input);

        /** @var list<array{product_id: int|string, quantity: int|string}> $items */
        $items = array_values($data['items']);
        $id = $this->checkout->placeOrder((int) $user->getId(), $items, $data);

        return new JsonResponse(['data' => $this->orders->present([$this->order($id)])[0]], 201);
    }

    #[Route('/me/orders', name: 'my_orders', methods: ['GET'])]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
    public function myOrders(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $page = Paginator::currentPage($request);
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM orders WHERE user_id = ?', [$user->getId()]);
        $rows = $this->db->fetchAllAssociative('SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 10 OFFSET '.(($page - 1) * 10), [$user->getId()]);

        return new JsonResponse([
            'data' => $this->orders->present($rows),
            'meta' => ['current_page' => $page, 'last_page' => max((int) ceil($total / 10), 1), 'total' => $total],
        ]);
    }

    #[Route('/me/orders/{number}', name: 'my_order', methods: ['GET'])]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
    public function myOrder(string $number, #[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(['data' => $this->orders->present([$this->myOrderRow($number, $user)])[0]]);
    }

    /** Покупатель отменяет сам, пока заказ не начали собирать. Деньги — обратно на баланс. */
    #[Route('/me/orders/{number}/cancel', name: 'my_order_cancel', methods: ['POST'])]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
    public function cancel(string $number, #[CurrentUser] User $user): JsonResponse
    {
        $order = $this->myOrderRow($number, $user);
        if (Orders::PAID !== $order['status']) {
            return new JsonResponse(['message' => 'Заказ уже собирают — отменить его может только поддержка.'], 422);
        }

        $this->checkout->cancelOrder((int) $order['id'], (int) $user->getId());

        return new JsonResponse(['data' => $this->orders->present([$this->order((int) $order['id'])])[0]]);
    }

    /** @return array<string, mixed> */
    private function myOrderRow(string $number, User $user): array
    {
        return $this->db->fetchAssociative('SELECT * FROM orders WHERE user_id = ? AND number = ? LIMIT 1', [$user->getId(), $number])
            ?: throw new NotFoundHttpException();
    }

    /** @return array<string, mixed> */
    private function order(int $id): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM orders WHERE id = ?', [$id]);
        \assert(false !== $row);

        return $row;
    }

    /**
     * Product::toApi().
     *
     * @param array<string, mixed>             $p
     * @param array<int, array<string, mixed>> $covers
     *
     * @return array<string, mixed>
     */
    private function present(array $p, array $covers, bool $full = false): array
    {
        $cover = $covers[(int) $p['cover_media_id']] ?? null;
        $stock = (int) $p['stock'];

        return [
            'id' => (int) $p['id'],
            'slug' => $p['slug'],
            'title' => $p['title'],
            'category' => $p['category'],
            'material' => $p['material'],
            'country' => $p['country'],
            'color' => $p['color'],
            'lead' => $p['lead'],
            'price' => (int) $p['price'],
            'old_price' => null === $p['old_price'] ? null : (int) $p['old_price'],
            'in_stock' => $stock > 0,
            // Точный остаток покупателю не нужен — только «мало» или «есть».
            'stock_left' => $stock > 0 && $stock <= 5 ? $stock : null,
            'cover' => null === $cover ? null : ['url' => $cover['url'], 'alt' => $cover['alt']],
        ] + ($full ? ['description' => $p['description'], 'sku' => $p['sku']] : []);
    }
}
