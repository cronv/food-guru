<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Цена и публикация платного — у руководителя редакции и выше.
 *
 * Иначе контент-менеджер мог бы выставить товар за 1 ₽ или сделать книгу
 * бесплатной, купить и вернуть цену обратно. Черновики он готовит как раньше:
 * цену неопубликованного менять можно, опубликовать — нет.
 */
trait GuardsCommerceFields
{
    /** @param  list<string>  $priceFields */
    private function guardCommerce(Request $request, ?Model $item, array $data, array $priceFields): void
    {
        if ($request->user()->atLeast(UserRole::EditorLead)) {
            return;
        }

        $published = (bool) ($item?->getAttribute('is_published'));

        if (! $published && ($data['is_published'] ?? false)) {
            abort(response()->json(['message' => 'Опубликовать может руководитель редакции. Сохраните как черновик.', 'code' => 'publish_forbidden'], 403));
        }

        if ($published) {
            foreach ($priceFields as $field) {
                if (array_key_exists($field, $data) && $data[$field] != $item->getAttribute($field)) {
                    abort(response()->json(['message' => 'Цену опубликованного меняет руководитель редакции.', 'code' => 'price_forbidden'], 403));
                }
            }
            if (array_key_exists('is_published', $data) && ! $data['is_published']) {
                abort(response()->json(['message' => 'Снять с публикации может руководитель редакции.', 'code' => 'publish_forbidden'], 403));
            }
        }
    }
}
