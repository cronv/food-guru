<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Строка таблицы лидеров. Почты и роли здесь нет намеренно:
 * таблица открыта всем, включая не авторизованных.
 *
 * @mixin User
 */
class LeaderboardRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'rating_points' => (int) $this->rating_points,
            'rating_level' => $this->rating_level->value,
            'rating_label' => $this->rating_level->label(),
            'streak_days' => (int) $this->streak_days,
            'recipes_count' => $this->whenCounted('recipes'),
            'reviews_count' => $this->whenCounted('reviews'),
            // Позиция в таблице проставляется контроллером: ресурс не знает о странице.
            'position' => $this->when(isset($this->position), fn () => (int) $this->position),
            'is_me' => $request->user()?->id === $this->id,
        ];
    }
}
