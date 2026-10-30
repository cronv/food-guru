<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\EmailTokenType;
use App\Enums\UserRole;
use App\Http\Resources\UserResource;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\EmailTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminUserController
{
    public function __construct(private EmailTokenService $tokens) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('viewAny', User::class), 403);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', 'string', 'in:user,content_manager,editor_lead,admin'],
            'verified' => ['nullable', 'in:yes,no'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = User::query()->latest('id');

        if (! empty($data['q'])) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', '%'.$data['q'].'%')
                ->orWhere('email', 'like', '%'.$data['q'].'%'));
        }
        if (! empty($data['role'])) {
            $query->where('role', $data['role']);
        }
        if (! empty($data['verified'])) {
            $data['verified'] === 'yes'
                ? $query->whereNotNull('email_verified_at')
                : $query->whereNull('email_verified_at');
        }

        return UserResource::collection($query->paginate($data['per_page'] ?? 20)->withQueryString());
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('manage', $user), 403);

        $data = $request->validate([
            'role' => ['nullable', 'string', 'in:user,content_manager,editor_lead,admin'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (isset($data['role'])) {
            $role = UserRole::from($data['role']);

            if (! $actor->can('assignRole', [$user, $role])) {
                return response()->json([
                    'message' => 'Эту роль назначать нельзя.',
                    'code' => 'role_not_allowed',
                ], 403);
            }

            $before = $user->role->value;
            $user->role = $role;
            ActivityLog::record($actor, 'user.role_changed', $user, [
                'from' => $before,
                'to' => $role->value,
                'email' => $user->email,
            ]);
        }

        if (array_key_exists('is_active', $data)) {
            $user->is_active = $data['is_active'];

            if (! $data['is_active']) {
                // Выход отовсюду: удалить сессии и обнулить «запомнить меня».
                $user->endSessions();
            }
            ActivityLog::record($actor, $data['is_active'] ? 'user.enabled' : 'user.disabled', $user, [
                'email' => $user->email,
            ]);
        }

        $user->save();

        return response()->json(['data' => new UserResource($user->fresh())]);
    }

    /** Выслать письмо подтверждения заново — если человек его не получил. */
    public function resendVerification(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('manage', $user), 403);

        if ($user->hasVerifiedEmail() && ! $user->pending_email) {
            return response()->json([
                'message' => 'Адрес уже подтверждён.',
                'code' => 'already_verified',
            ], 422);
        }

        if ($this->tokens->nextAllowedAt($user, EmailTokenType::Verify)) {
            return response()->json([
                'message' => 'Письмо недавно уже отправляли. Подождите минуту.',
                'code' => 'cooldown',
            ], 429);
        }

        $this->tokens->sendVerification($user);
        ActivityLog::record($request->user(), 'user.verification_resent', $user, ['email' => $user->email]);

        return response()->json(['data' => ['status' => 'sent']]);
    }
}
