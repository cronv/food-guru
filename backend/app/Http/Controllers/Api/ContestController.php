<?php

namespace App\Http\Controllers\Api;

use App\Enums\EntryStatus;
use App\Http\Resources\ContestCardResource;
use App\Http\Resources\ContestEntryResource;
use App\Http\Resources\ContestResource;
use App\Models\ActivityLog;
use App\Models\Contest;
use App\Models\ContestEntry;
use App\Models\ContestVote;
use App\Services\MediaService;
use App\Support\DatabaseError;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ContestController
{
    public function __construct(private MediaService $media) {}

    /**
     * Список конкурсов. Один маршрут обслуживает и активные, и архив:
     * фаза считается из дат, поэтому фильтровать по ней в SQL нельзя —
     * отбираем по датам, а подписи расставляет ресурс.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'kind' => ['nullable', 'array'],
            'kind.*' => ['string', 'in:photo,video,recipe'],
            'scope' => ['nullable', 'string', 'in:active,archive,all'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $scope = $data['scope'] ?? 'active';

        $query = Contest::published()
            ->with('cover')
            ->search($data['q'] ?? null);

        if (! empty($data['kind'])) {
            $query->whereIn('kind', $data['kind']);
        }

        /*
         * Архив — конкурсы, у которых объявлены итоги.
         * Активные — всё остальное, сначала те, где дедлайн ближе.
         */
        if ($scope === 'archive') {
            $query->whereNotNull('results_at')
                ->where('results_at', '<=', now())
                ->orderByDesc('results_at');
        } elseif ($scope === 'active') {
            $query->where(function ($q) {
                $q->whereNull('results_at')->orWhere('results_at', '>', now());
            })->orderByRaw('COALESCE(voting_close_at, submissions_close_at, published_at) asc');
        } else {
            $query->orderByDesc('published_at');
        }

        return ContestCardResource::collection($query->paginate(12)->withQueryString());
    }

    public function show(Request $request, string $slug): ContestResource
    {
        $contest = Contest::where('slug', $slug)
            ->with(['cover', 'author', 'winners.author', 'winners.media'])
            ->firstOrFail();

        abort_unless($request->user()?->can('view', $contest) ?? $contest->isPublic(), 404);

        return (new ContestResource($contest))
            ->withViewer($this->viewerState($request, $contest));
    }

    /** Галерея принятых работ. Сортировка по голосам или по свежести. */
    public function entries(Request $request, string $slug): AnonymousResourceCollection
    {
        $contest = Contest::published()->where('slug', $slug)->firstOrFail();

        $sort = $request->string('sort')->toString();

        $query = $contest->entries()
            ->approved()
            ->with(['author', 'media']);

        if ($sort === 'new') {
            $query->latest();
        } else {
            $query->orderByDesc('votes_count')->orderBy('id');
        }

        return ContestEntryResource::collection($query->paginate(24)->withQueryString());
    }

    /** Прислать работу на конкурс. */
    public function storeEntry(Request $request, string $slug): JsonResponse
    {
        $contest = Contest::published()->where('slug', $slug)->firstOrFail();
        $user = $request->user();

        if (! $contest->acceptsEntries()) {
            return response()->json([
                'message' => 'Приём работ на этот конкурс закрыт.',
                'code' => 'submissions_closed',
            ], 422);
        }

        $sent = $contest->entries()->where('user_id', $user->id)->count();

        if ($sent >= $contest->max_entries_per_user) {
            return response()->json([
                'message' => $contest->max_entries_per_user === 1
                    ? 'Вы уже прислали работу на этот конкурс.'
                    : "На этот конкурс можно прислать не больше {$contest->max_entries_per_user} работ.",
                'code' => 'entry_limit',
            ], 409);
        }

        $kind = $contest->kind;

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:2000'],
            'files' => ['required', 'array', 'min:1', 'max:'.$kind->maxFiles()],
            'files.*' => ['file', 'mimes:'.implode(',', $kind->mimes()), 'max:'.$kind->maxFileKb()],
        ], [
            'files.required' => 'Приложите хотя бы один файл.',
            'files.max' => 'К работе можно приложить не больше '.$kind->maxFiles().' файлов.',
            'files.*.mimes' => 'Подойдут файлы: '.implode(', ', $kind->mimes()).'.',
            'files.*.max' => 'Каждый файл должен быть меньше '.round($kind->maxFileKb() / 1024).' МБ.',
        ]);

        $entry = DB::transaction(function () use ($contest, $user, $data, $request) {
            // Повторный подсчёт под блокировкой строки конкурса: два одновременных
            // запроса иначе оба видят «0 работ» и оба проходят лимит.
            $locked = Contest::whereKey($contest->id)->lockForUpdate()->first();
            if ($locked->entries()->where('user_id', $user->id)->count() >= $locked->max_entries_per_user) {
                abort(response()->json([
                    'message' => 'Вы уже прислали максимум работ на этот конкурс.',
                    'code' => 'entry_limit',
                ], 409));
            }

            $entry = ContestEntry::create([
                'contest_id' => $contest->id,
                'user_id' => $user->id,
                'title' => $data['title'] ?? null,
                'body' => $data['body'] ?? null,
                'status' => EntryStatus::Pending,
            ]);

            foreach ($request->file('files', []) as $i => $file) {
                $media = $this->media->store($file, $user, $contest->title.' — работа участника');
                $entry->media()->attach($media->id, ['position' => $i]);
            }

            $contest->increment('entries_count');

            ActivityLog::record($user, 'contest.entry.created', $entry, ['contest' => $contest->slug]);

            return $entry;
        });

        return response()->json([
            'data' => new ContestEntryResource($entry->load(['author', 'media'])),
            'meta' => ['message' => 'Работа отправлена. Она появится в галерее после проверки.'],
        ], 201);
    }

    /** Отдать голос. Один голос на конкурс, переголосовать нельзя. */
    public function vote(Request $request, string $slug, ContestEntry $entry): JsonResponse
    {
        $contest = Contest::published()->where('slug', $slug)->firstOrFail();
        $user = $request->user();

        abort_unless($entry->contest_id === $contest->id, 404);

        if (! $contest->acceptsVotes()) {
            return response()->json([
                'message' => 'Голосование по этому конкурсу закрыто.',
                'code' => 'voting_closed',
            ], 422);
        }

        if ($entry->status !== EntryStatus::Approved) {
            return response()->json([
                'message' => 'За эту работу голосовать нельзя: она ещё не прошла проверку.',
                'code' => 'entry_not_approved',
            ], 422);
        }

        if ($entry->user_id === $user->id) {
            return response()->json([
                'message' => 'За свою работу голосовать нельзя.',
                'code' => 'own_entry',
            ], 422);
        }

        /*
         * Уникальный индекс (contest_id, user_id) — единственная настоящая защита
         * от двойного голоса: проверка запросом не спасает от двух одновременных.
         */
        try {
            DB::transaction(function () use ($contest, $entry, $user) {
                ContestVote::create([
                    'contest_id' => $contest->id,
                    'contest_entry_id' => $entry->id,
                    'user_id' => $user->id,
                ]);

                $entry->increment('votes_count');
                $contest->increment('votes_count');
            });
        } catch (QueryException $e) {
            if (! DatabaseError::isDuplicateKey($e)) {
                throw $e;
            }

            return response()->json([
                'message' => 'Вы уже голосовали в этом конкурсе.',
                'code' => 'already_voted',
            ], 409);
        }

        return response()->json([
            'data' => [
                'entry_id' => $entry->id,
                'votes_count' => $entry->fresh()->votes_count,
            ],
        ]);
    }

    /** Свои работы во всех конкурсах — для личного кабинета. */
    public function myEntries(Request $request): AnonymousResourceCollection
    {
        $entries = ContestEntry::where('user_id', $request->user()->id)
            ->with(['media', 'contest'])
            ->latest()
            ->paginate(12);

        return ContestEntryResource::collection($entries);
    }

    /**
     * Что доступно именно этому посетителю. Один запрос на две величины,
     * и только для авторизованных — гостю считать нечего.
     */
    private function viewerState(Request $request, Contest $contest): array
    {
        $user = $request->user();

        if (! $user) {
            return ['can_submit' => false, 'can_vote' => false, 'entries_left' => 0];
        }

        $mine = $contest->entries()
            ->where('user_id', $user->id)
            ->with('media')
            ->get();

        $votedEntryId = ContestVote::where('contest_id', $contest->id)
            ->where('user_id', $user->id)
            ->value('contest_entry_id');

        $left = max(0, $contest->max_entries_per_user - $mine->count());

        return [
            'can_submit' => $contest->acceptsEntries() && $user->hasVerifiedEmail() && $left > 0,
            'can_vote' => $contest->acceptsVotes() && $user->hasVerifiedEmail() && $votedEntryId === null,
            'entries_left' => $left,
            'voted_entry_id' => $votedEntryId,
            'my_entries' => $mine,
        ];
    }
}
