<?php

namespace App\Http\Controllers\Api;

use App\Models\Order;
use App\Models\Product;
use App\Services\Commerce\CatalogFilters;
use App\Services\Commerce\Checkout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShopController
{
    /**
     * Каталог с фильтрами. Списки (категория, материал, страна, цвет) — по нескольку
     * значений: category[]=Ножи&category[]=Доски. Цена — в копейках.
     */
    public function products(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'array', 'max:20'], 'category.*' => ['string', 'max:80'],
            'material' => ['nullable', 'array', 'max:20'], 'material.*' => ['string', 'max:80'],
            'country' => ['nullable', 'array', 'max:20'], 'country.*' => ['string', 'max:80'],
            'color' => ['nullable', 'array', 'max:20'], 'color.*' => ['string', 'max:40'],
            'price_min' => ['nullable', 'integer', 'min:0'],
            'price_max' => ['nullable', 'integer', 'min:0'],
            'in_stock' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:popular,price_asc,price_desc,new,title,discount'],
        ]);

        $query = Product::published()->with('cover');

        CatalogFilters::search($query, $data['q'] ?? null);
        foreach (['category', 'material', 'country', 'color'] as $field) {
            CatalogFilters::in($query, $field, $data[$field] ?? null);
        }
        CatalogFilters::price($query, $data['price_min'] ?? null, $data['price_max'] ?? null);
        if ($request->boolean('in_stock')) {
            $query->where('stock', '>', 0);
        }

        // Отсутствующее — всегда в конце, какой бы ни была сортировка.
        $query->orderByRaw('stock > 0 desc');
        match ($data['sort'] ?? 'popular') {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'new' => $query->orderByDesc('created_at'),
            'title' => $query->orderBy('title'),
            'discount' => $query->orderByRaw('CASE WHEN old_price IS NULL THEN 0 ELSE (old_price - price) * 1.0 / old_price END DESC'),
            // «Популярные»: пока продаж мало — сперва со скидкой, потом по названию.
            default => $query->orderByRaw('old_price IS NULL')->orderBy('title'),
        };

        $all = Product::published();

        return response()->json([
            'data' => $query->get()->map->toApi(),
            'meta' => [
                'total' => Product::published()->count(),
                'facets' => [
                    'categories' => CatalogFilters::facet($all, 'category'),
                    'materials' => CatalogFilters::facet($all, 'material'),
                    'countries' => CatalogFilters::facet($all, 'country'),
                    'colors' => CatalogFilters::facet($all, 'color'),
                    'price' => CatalogFilters::priceRange($all),
                ],
                // Старое поле — для совместимости с уже открытыми вкладками.
                'categories' => CatalogFilters::facet($all, 'category'),
            ],
        ]);
    }

    public function product(string $slug): JsonResponse
    {
        $product = Product::published()->where('slug', $slug)->with('cover')->firstOrFail();

        return response()->json(['data' => $product->toApi(true)]);
    }

    /** Способы доставки с ценами — корзина считает итог по ним же. */
    public function delivery(): JsonResponse
    {
        return response()->json(['data' => collect(Order::DELIVERY)
            ->map(fn ($d, $code) => ['code' => $code] + $d)->values()]);
    }

    /** Оформить заказ. Цены берутся из базы: присланные корзиной суммы не используются. */
    public function placeOrder(Request $request, Checkout $checkout): JsonResponse
    {
        $needsAddress = (bool) (Order::DELIVERY[$request->input('delivery_method')]['needs_address'] ?? false);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'delivery_method' => ['required', Rule::in(array_keys(Order::DELIVERY))],
            'recipient_name' => ['required', 'string', 'min:2', 'max:160'],
            'phone' => ['required', 'string', 'regex:/^\+?[\d\s\-()]{10,20}$/'],
            'city' => [Rule::requiredIf($needsAddress), 'nullable', 'string', 'max:120'],
            'address' => [Rule::requiredIf($needsAddress), 'nullable', 'string', 'max:400'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [
            'items.required' => 'Корзина пуста.',
            'recipient_name.required' => 'Укажите, кто получит заказ.',
            'phone.required' => 'Укажите телефон для связи.',
            'phone.regex' => 'Телефон выглядит неверно — проверьте цифры.',
            'city.required' => 'Укажите город.',
            'address.required' => 'Укажите адрес доставки.',
        ]);

        $order = $checkout->placeOrder($request->user(), $data['items'], $data);

        return response()->json(['data' => $order->toApi()], 201);
    }

    public function myOrders(Request $request): JsonResponse
    {
        $orders = Order::where('user_id', $request->user()->id)->with('items')->latest()->paginate(10);

        return response()->json([
            'data' => $orders->getCollection()->map->toApi(),
            'meta' => ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'total' => $orders->total()],
        ]);
    }

    public function myOrder(Request $request, string $number): JsonResponse
    {
        $order = Order::where('user_id', $request->user()->id)->where('number', $number)->with('items')->firstOrFail();

        return response()->json(['data' => $order->toApi()]);
    }

    /** Покупатель отменяет сам, пока заказ не начали собирать. Деньги — обратно на баланс. */
    public function cancel(Request $request, string $number, Checkout $checkout): JsonResponse
    {
        $order = Order::where('user_id', $request->user()->id)->where('number', $number)->firstOrFail();

        if ($order->status !== Order::PAID) {
            return response()->json(['message' => 'Заказ уже собирают — отменить его может только поддержка.'], 422);
        }

        return response()->json(['data' => $checkout->cancelOrder($order, $request->user())->toApi()]);
    }
}
