<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'pending_email' => $this->pending_email,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'email_verified' => $this->hasVerifiedEmail(),
            'has_password' => $this->hasPassword(),
            'is_staff' => $this->isStaff(),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),

            'rating' => [
                'points' => (int) $this->rating_points,
                'level' => $this->rating_level->value,
                'level_label' => $this->rating_level->label(),
                'next_level' => $this->rating_level->next()?->value,
                'next_level_label' => $this->rating_level->next()?->label(),
                'points_to_next' => $this->pointsToNextLevel(),
                'progress' => round($this->levelProgress(), 3),
                'streak_days' => (int) $this->streak_days,
                'streak_best' => (int) $this->streak_best,
                'visits_total' => (int) $this->visits_total,
            ],
        ];
    }
}
