<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\EntryStatus;
use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Http\Resources\ContestCardResource;
use App\Http\Resources\ContestEntryResource;
use App\Http\Resources\ContestResource;
use App\Models\ActivityLog;
use App\Models\Contest;
use App\Models\ContestEntry;
use App\Support\Slug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminContestController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,moderation,published,archived'],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Contest::query()->with('cover')->latest('updated_at');

        // Контент-менеджер видит только свои конкурсы.
        if (! $request->user()->atLeast(UserRole::EditorLead)) {
            $query->where('author_id', $request->user()->id);
        }

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['q'])) {
            $query->where('title', 'like', '%'.$data['q'].'%');
        }

        return ContestCardResource::collection(
            $query->paginate($data['per_page'] ?? 20)->withQueryString()
        );
    }

    public function show(Request $request, Contest $contest): ContestResource
    {
        abort_unless($request->user()->can('view', $contest), 403);

        return new ContestResource($contest->load(['cover', 'author', 'winners.author', 'winners.media']));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('create', Contest::class), 403);

        $data = $this->validateContest($request, creating: true);

        $contest = Contest::create([
            'author_id' => $request->user()->id,
            'cover_media_id' => $data['cover_media_id'] ?? null,
            'slug' => Slug::unique(Contest::class, $data['slug'] ?? $data['title'], 'contest'),
            'title' => $data['title'],
            'lead' => $data['lead'] ?? null,
            'description' => $data['description'] ?? null,
            'rules' => $data['rules'] ?? null,
            'prize' => $data['prize'] ?? null,
            'kind' => $data['kind'] ?? 'photo',
            'status' => PublishStatus::Draft,
            'submissions_open_at' => $data['submissions_open_at'] ?? null,
            'submissions_close_at' => $data['submissions_close_at'] ?? null,
            'voting_open_at' => $data['voting_open_at'] ?? null,
            'voting_close_at' => $data['voting_close_at'] ?? null,
            'results_at' => $data['results_at'] ?? null,
            'max_entries_per_user' => $data['max_entries_per_user'] ?? 1,
        ]);

        ActivityLog::record($request->user(), 'contest.created', $contest, ['title' => $contest->title]);

        return response()->json(['data' => new ContestResource($contest->load('cover'))], 201);
    }

    public function update(Request $request, Contest $contest): JsonResponse
    {
        abort_unless($request->user()->can('update', $contest), 403);

        $data = $this->validateContest($request, creating: false);

        /*
         * Даты пишем отдельно от остальных полей: им нужно разрешить null,
         * а array_filter по null как раз выкинет «очистить дату».
         */
        $dates = ['submissions_open_at', 'submissions_close_at', 'voting_open_at', 'voting_close_at', 'results_at'];

        $contest->fill(array_filter([
            'cover_media_id' => $data['cover_media_id'] ?? null,
            'title' => $data['title'] ?? null,
            'lead' => $data['lead'] ?? null,
            'description' => $data['description'] ?? null,
            'rules' => $data['rules'] ?? null,
            'prize' => $data['prize'] ?? null,
            'kind' => $data['kind'] ?? null,
            'max_entries_per_user' => $data['max_entries_per_user'] ?? null,
        ], fn ($v) => $v !== null));

        foreach ($dates as $field) {
            if (array_key_exists($field, $data)) {
                $contest->{$field} = $data[$field];
            }
        }

        if (! empty($data['slug']) && $data['slug'] !== $contest->slug) {
            $contest->slug = Slug::unique(Contest::class, $data['slug'], 'contest', $contest->id);
        }

        $contest->save();

        ActivityLog::record($request->user(), 'contest.updated', $contest, ['title' => $contest->title]);

        return response()->json(['data' => new ContestResource($contest->fresh(['cover', 'author']))]);
    }

    /** Переход статуса: publish, archive, draft. */
    public function move(Request $request, Contest $contest, string $action): JsonResponse
    {
        $to = match ($action) {
            'publish' => PublishStatus::Published,
            'archive' => PublishStatus::Archived,
            'draft' => PublishStatus::Draft,
            'moderation' => PublishStatus::Moderation,
            default => abort(404),
        };

        $ability = in_array($to, [PublishStatus::Published, PublishStatus::Archived], true) ? 'publish' : 'update';
        abort_unless($request->user()->can($ability, $contest), 403);

        if (! $contest->status->canMoveTo($to)) {
            return response()->json([
                'message' => "Из статуса «{$contest->status->label()}» нельзя перейти в «{$to->label()}».",
                'code' => 'invalid_transition',
            ], 422);
        }

        if ($to === PublishStatus::Published && blank($contest->submissions_open_at)) {
            return response()->json([
                'message' => 'Укажите дату начала приёма работ — без неё конкурс нельзя опубликовать.',
                'code' => 'dates_missing',
            ], 422);
        }

        $from = $contest->status;
        $contest->status = $to;

        if ($to === PublishStatus::Published && ! $contest->published_at) {
            $contest->published_at = now();
        }

        $contest->save();

        ActivityLog::record($request->user(), 'contest.'.$action, $contest, [
            'from' => $from->value,
            'to' => $to->value,
            'title' => $contest->title,
        ]);

        return response()->json([
            'data' => ['status' => $to->value, 'status_label' => $to->label()],
        ]);
    }

    /** Присланные работы: по умолчанию те, что ждут проверки. */
    public function entries(Request $request, Contest $contest): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('moderateEntries', $contest), 403);

        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,approved,rejected'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $query = $contest->entries()->with(['author', 'media']);

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        } else {
            $query->where('status', EntryStatus::Pending);
        }

        return ContestEntryResource::collection(
            $query->orderBy('created_at')->paginate($data['per_page'] ?? 30)->withQueryString()
        );
    }

    /** Принять или отклонить работу. */
    public function moderateEntry(Request $request, Contest $contest, ContestEntry $entry): JsonResponse
    {
        abort_unless($request->user()->can('moderateEntries', $contest), 403);
        abort_unless($entry->contest_id === $contest->id, 404);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:approved,rejected'],
            'reason' => ['nullable', 'string', 'max:300', 'required_if:status,rejected'],
        ], [
            'reason.required_if' => 'Напишите автору, почему работа не подошла.',
        ]);

        $status = EntryStatus::from($data['status']);
        $reason = $data['status'] === 'rejected' ? ($data['reason'] ?? null) : null;

        DB::transaction(function () use ($entry, $contest, $status, $reason) {
            // Отклонённая работа уходит из галереи вместе со своими голосами,
            // иначе счётчик конкурса останется завышенным.
            if ($status === EntryStatus::Rejected && $entry->votes_count > 0) {
                $contest->decrement('votes_count', $entry->votes_count);
                $entry->votes()->delete();
                $entry->forceFill(['votes_count' => 0]);
            }

            $entry->status = $status;
            $entry->reject_reason = $reason;
            $entry->save();
        });

        ActivityLog::record($request->user(), 'contest.entry.'.$status->value, $entry, [
            'contest' => $contest->slug,
        ]);

        return response()->json(['data' => new ContestEntryResource($entry->load(['author', 'media']))]);
    }

    /**
     * Назначить победителей. Приходит весь список мест сразу —
     * так нельзя получить двух вторых и ни одного первого.
     */
    public function setWinners(Request $request, Contest $contest): JsonResponse
    {
        abort_unless($request->user()->can('awardWinners', $contest), 403);

        $data = $request->validate([
            'places' => ['present', 'array', 'max:3'],
            'places.*.entry_id' => ['required', 'integer', 'exists:contest_entries,id'],
            'places.*.place' => ['required', 'integer', 'min:1', 'max:3'],
        ]);

        $places = collect($data['places']);

        if ($places->pluck('place')->duplicates()->isNotEmpty()) {
            return response()->json([
                'message' => 'Два участника не могут занять одно место.',
                'code' => 'duplicate_place',
            ], 422);
        }

        DB::transaction(function () use ($contest, $places, $request) {
            $contest->entries()->whereNotNull('place')->update(['place' => null]);

            foreach ($places as $row) {
                $contest->entries()
                    ->where('id', $row['entry_id'])
                    ->where('status', EntryStatus::Approved)
                    ->update(['place' => $row['place']]);
            }

            // Итоги объявлены — с этого момента конкурс уезжает в архив.
            if (blank($contest->results_at) && $places->isNotEmpty()) {
                $contest->forceFill(['results_at' => now()])->save();
            }

            ActivityLog::record($request->user(), 'contest.winners', $contest, [
                'places' => $places->all(),
            ]);
        });

        return response()->json([
            'data' => ContestEntryResource::collection(
                $contest->winners()->with(['author', 'media'])->get()
            ),
        ]);
    }

    private function validateContest(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        $data = $request->validate([
            'title' => [$required, 'string', 'min:3', 'max:200'],
            'slug' => ['nullable', 'string', 'max:200', 'regex:/^[a-z0-9-]+$/'],
            'lead' => ['nullable', 'string', 'max:600'],
            'description' => ['nullable', 'string', 'max:20000'],
            'rules' => ['nullable', 'string', 'max:20000'],
            'prize' => ['nullable', 'string', 'max:600'],
            'kind' => ['nullable', 'in:photo,video,recipe'],
            'cover_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'max_entries_per_user' => ['nullable', 'integer', 'min:1', 'max:10'],

            'submissions_open_at' => ['nullable', 'date'],
            'submissions_close_at' => ['nullable', 'date'],
            'voting_open_at' => ['nullable', 'date'],
            'voting_close_at' => ['nullable', 'date'],
            'results_at' => ['nullable', 'date'],
        ], [
            'slug.regex' => 'В адресе допустимы только латинские буквы, цифры и дефис.',
        ]);

        $this->assertDatesInOrder($data);

        return $data;
    }

    /**
     * Порядок дат проверяем вручную, а не правилами after/after_or_equal.
     *
     * Эти правила сравнивают с полем, которое может быть пустым, и тогда
     * выдают невнятное сообщение про несуществующую дату. Редактор же
     * заполняет сроки по частям, и пустая половина — нормальное состояние
     * черновика. Поэтому сравниваем только те пары, где обе даты заданы.
     */
    private function assertDatesInOrder(array $data): void
    {
        $pairs = [
            ['submissions_open_at', 'submissions_close_at', false,
                'Приём работ должен заканчиваться позже, чем начинается.'],
            ['submissions_close_at', 'voting_open_at', true,
                'Голосование не может начаться раньше, чем закроется приём работ.'],
            ['voting_open_at', 'voting_close_at', false,
                'Голосование должно заканчиваться позже, чем начинается.'],
            ['voting_close_at', 'results_at', true,
                'Итоги объявляются не раньше, чем закроется голосование.'],
        ];

        foreach ($pairs as [$earlier, $later, $allowEqual, $message]) {
            if (blank($data[$earlier] ?? null) || blank($data[$later] ?? null)) {
                continue;
            }

            $from = strtotime((string) $data[$earlier]);
            $to = strtotime((string) $data[$later]);

            if ($from === false || $to === false) {
                continue;
            }

            if ($allowEqual ? $to < $from : $to <= $from) {
                throw ValidationException::withMessages([$later => $message]);
            }
        }
    }
}
