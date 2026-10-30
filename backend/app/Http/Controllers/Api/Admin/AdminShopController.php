<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Services\Commerce\Checkout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Товары — контент-менеджер и выше; заказы — руководитель редакции и выше (см. routes). */
class AdminShopController
{
    use GuardsCommerceFields;

    public function products(Request $request): JsonResponse
    {
        $q = $request->query('q');

        $products = Product::with('cover')
            ->when($q, fn ($query) => $query->where('title', 'like', '%'.$q.'%'))
            ->orderBy('title')
            ->paginate(30);

        return response()->json([
            'data' => $products->getCollection()->map(fn (Product $p) => $this->row($p)),
            'meta' => ['current_page' => $products->currentPage(), 'last_page' => $products->lastPage(), 'total' => $products->total()],
        ]);
    }

    public function product(Product $product): JsonResponse
    {
        return response()->json(['data' => $this->row($product->load('cover'))]);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->guardCommerce($request, null, $data, ['price', 'old_price']);
        $data['slug'] ??= $this->slug($data['title']);

        $product = Product::create($data);
        ActivityLog::record($request->user(), 'product.created', $product, ['title' => $product->title]);

        return response()->json(['data' => $this->row($product->load('cover'))], 201);
    }

    public function updateProduct(Request $request, Product $product): JsonResponse
    {
        $data = $this->validated($request, $product);
        $this->guardCommerce($request, $product, $data, ['price', 'old_price']);
        $product->update($data);
        ActivityLog::record($request->user(), 'product.updated', $product, ['title' => $product->title]);

        return response()->json(['data' => $this->row($product->load('cover'))]);
    }

    public function orders(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in([...array_keys(Order::STATUSES), 'active', 'all'])]]);
        $status = $data['status'] ?? 'active';

        $orders = Order::with(['items', 'user'])
            ->when($status === 'active', fn ($q) => $q->whereIn('status', [Order::PAID, Order::ASSEMBLING, Order::SHIPPED]))
            ->when(! in_array($status, ['active', 'all'], true), fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => $orders->getCollection()->map(fn (Order $o) => $o->toApi(true)),
            'meta' => [
                'current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'total' => $orders->total(),
                'statuses' => collect(Order::STATUSES)->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values(),
            ],
        ]);
    }

    /** Перевести заказ дальше по цепочке. Отмена — с возвратом денег и товара. */
    public function updateOrder(Request $request, Order $order, Checkout $checkout): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Order::STATUSES))]]);

        if ($data['status'] === Order::CANCELED) {
            // Отмена сама берёт блокировку и проверяет переход внутри транзакции.
            $order = $checkout->cancelOrder($order, $request->user());

            return response()->json(['data' => $order->load(['items', 'user'])->toApi(true)]);
        }

        // Статус перечитываем под блокировкой строки: иначе покупатель мог отменить
        // заказ (и получить деньги) в ту же секунду, а мы перезаписали бы «Отменён»
        // на «Собирается» — и отправили бы оплаченный обратно товар.
        $order = DB::transaction(function () use ($order, $data, $request) {
            $fresh = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $fresh->canMoveTo($data['status'])) {
                throw ValidationException::withMessages(['status' => 'Из статуса «'.Order::STATUSES[$fresh->status].'» в «'.Order::STATUSES[$data['status']].'» перевести нельзя.']);
            }

            $stamp = ['shipped' => 'shipped_at', 'delivered' => 'delivered_at'][$data['status']] ?? null;
            $fresh->forceFill(['status' => $data['status']] + ($stamp ? [$stamp => now()] : []))->save();
            ActivityLog::record($request->user(), 'order.'.$data['status'], $fresh, ['number' => $fresh->number]);

            return $fresh;
        });

        return response()->json(['data' => $order->load(['items', 'user'])->toApi(true)]);
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        $creating = $product === null;

        return $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:160', 'regex:/^[a-z0-9-]+$/', Rule::unique('products', 'slug')->ignore($product?->id)],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'material' => ['sometimes', 'nullable', 'string', 'max:80'],
            'country' => ['sometimes', 'nullable', 'string', 'max:80'],
            'color' => ['sometimes', 'nullable', 'string', 'max:40'],
            'lead' => ['sometimes', 'nullable', 'string', 'max:400'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:64'],
            // Копейки.
            'price' => [$creating ? 'required' : 'sometimes', 'integer', 'min:100', 'max:100000000'],
            'old_price' => ['sometimes', 'nullable', 'integer', 'gt:price'],
            'stock' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'cover_media_id' => ['sometimes', 'nullable', 'integer', 'exists:media,id'],
            'is_published' => ['sometimes', 'boolean'],
        ], [
            'old_price.gt' => 'Старая цена должна быть больше текущей — иначе это не скидка.',
            'slug.regex' => 'Адрес — только латиница, цифры и дефис.',
        ]);
    }

    private function row(Product $p): array
    {
        return $p->toApi(true) + ['stock' => $p->stock, 'is_published' => $p->is_published, 'cover_media_id' => $p->cover_media_id];
    }

    private function slug(string $title): string
    {
        $base = Str::slug($title) ?: 'tovar';
        $slug = $base;
        for ($i = 2; Product::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
