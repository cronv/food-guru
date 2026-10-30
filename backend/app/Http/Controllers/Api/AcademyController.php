<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\AcademyCardResource;
use App\Http\Resources\AcademyResource;
use App\Models\AcademyRegistration;
use App\Models\AcademySession;
use App\Models\ActivityLog;
use App\Support\DatabaseError;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AcademyController
{
    /**
     * Расписание и архив записей.
     *
     * scope=upcoming — то, что ещё не началось, ближайшее первым;
     * scope=archive  — прошедшие эфиры и видеоуроки, свежие первыми.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'format' => ['nullable', 'array'],
            'format.*' => ['string', 'in:webinar,stream,lesson'],
            'level' => ['nullable', 'array'],
            'level.*' => ['string', 'in:basic,medium,advanced'],
            'scope' => ['nullable', 'string', 'in:upcoming,archive,all'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $scope = $data['scope'] ?? 'upcoming';

        $query = AcademySession::published()
            ->with('cover')
            ->search($data['q'] ?? null);

        if (! empty($data['format'])) {
            $query->whereIn('format', $data['format']);
        }

        if (! empty($data['level'])) {
            $query->whereIn('level', $data['level']);
        }

        if ($scope === 'upcoming') {
            $query->whereNotNull('starts_at')
                ->where('starts_at', '>=', now())
                ->orderBy('starts_at');
        } elseif ($scope === 'archive') {
            /*
             * В архиве два разных случая: прошедший эфир и видеоурок,
             * у которого времени старта нет вовсе.
             */
            $query->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<', now());
            })->orderByRaw('COALESCE(starts_at, published_at) desc');
        } else {
            $query->orderByRaw('COALESCE(starts_at, published_at) desc');
        }

        return AcademyCardResource::collection($query->paginate(12)->withQueryString());
    }

    public function show(Request $request, string $slug): AcademyResource
    {
        $session = AcademySession::where('slug', $slug)
            ->with(['cover', 'author'])
            ->firstOrFail();

        abort_unless($request->user()?->can('view', $session) ?? $session->isPublic(), 404);

        return (new AcademyResource($session))->withViewer($this->viewerState($request, $session));
    }

    /** Записаться на эфир. Без записи ссылки на эфир и запись не отдаются. */
    public function register(Request $request, string $slug): JsonResponse
    {
        $session = AcademySession::published()->where('slug', $slug)->firstOrFail();
        $user = $request->user();

        try {
            // Запись и счётчик — одна операция: иначе оборвавшийся запрос
            // оставит счётчик расходиться с числом записей.
            DB::transaction(function () use ($session, $user) {
                AcademyRegistration::create([
                    'academy_session_id' => $session->id,
                    'user_id' => $user->id,
                ]);

                $session->increment('registrations_count');
            });

            ActivityLog::record($user, 'academy.registered', $session, ['session' => $session->slug]);
        } catch (QueryException $e) {
            if (! DatabaseError::isDuplicateKey($e)) {
                throw $e;
            }
            // Повторное нажатие — не ошибка: человек и так записан.
        }

        $session = $session->fresh(['cover', 'author']);

        return response()->json([
            'data' => (new AcademyResource($session))
                ->withViewer($this->viewerState($request, $session)),
            'meta' => ['message' => $session->isUpcoming()
                ? 'Вы записаны. Напомним письмом за час до начала.'
                : 'Вы записаны. Запись открыта — можно смотреть.'],
        ]);
    }

    public function unregister(Request $request, string $slug): JsonResponse
    {
        $session = AcademySession::published()->where('slug', $slug)->firstOrFail();

        DB::transaction(function () use ($session, $request) {
            $deleted = AcademyRegistration::where('academy_session_id', $session->id)
                ->where('user_id', $request->user()->id)
                ->delete();

            if ($deleted) {
                $session->decrement('registrations_count');
            }
        });

        return response()->json(['data' => ['registered' => false]]);
    }

    /** Мои записи на эфиры — для личного кабинета. */
    public function myRegistrations(Request $request): AnonymousResourceCollection
    {
        $sessions = AcademySession::published()
            ->whereIn('id', AcademyRegistration::where('user_id', $request->user()->id)
                ->select('academy_session_id'))
            ->with('cover')
            ->orderByRaw('COALESCE(starts_at, published_at) desc')
            ->paginate(12);

        return AcademyCardResource::collection($sessions);
    }

    private function viewerState(Request $request, AcademySession $session): array
    {
        $user = $request->user();

        if (! $user) {
            return ['registered' => false, 'can_watch' => false, 'registered_at' => null];
        }

        $registration = AcademyRegistration::where('academy_session_id', $session->id)
            ->where('user_id', $user->id)
            ->first();

        $registered = $registration !== null;

        return [
            'registered' => $registered,
            'can_watch' => $user->can('watch', [$session, $registered]),
            'registered_at' => $registration?->created_at?->toIso8601String(),
        ];
    }
}
