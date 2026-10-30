<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AcademyFormat;
use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Http\Resources\AcademyCardResource;
use App\Http\Resources\AcademyResource;
use App\Models\AcademyRegistration;
use App\Models\AcademySession;
use App\Models\ActivityLog;
use App\Support\Slug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminAcademyController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,moderation,published,archived'],
            'format' => ['nullable', 'string', 'in:webinar,stream,lesson'],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = AcademySession::query()->with('cover')->latest('updated_at');

        if (! $request->user()->atLeast(UserRole::EditorLead)) {
            $query->where('author_id', $request->user()->id);
        }

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['format'])) {
            $query->where('format', $data['format']);
        }
        if (! empty($data['q'])) {
            $query->where('title', 'like', '%'.$data['q'].'%');
        }

        return AcademyCardResource::collection(
            $query->paginate($data['per_page'] ?? 20)->withQueryString()
        );
    }

    public function show(Request $request, AcademySession $session): AcademyResource
    {
        abort_unless($request->user()->can('view', $session), 403);

        // Редакция видит ссылки всегда — иначе материал не проверить до эфира.
        return (new AcademyResource($session->load(['cover', 'author'])))
            ->withViewer(['registered' => true, 'can_watch' => true]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('create', AcademySession::class), 403);

        $data = $this->validateSession($request, creating: true);

        $session = AcademySession::create([
            'author_id' => $request->user()->id,
            'cover_media_id' => $data['cover_media_id'] ?? null,
            'slug' => Slug::unique(AcademySession::class, $data['slug'] ?? $data['title'], 'lesson'),
            'title' => $data['title'],
            'lead' => $data['lead'] ?? null,
            'description' => $data['description'] ?? null,
            'takeaways' => $data['takeaways'] ?? null,
            'format' => $data['format'] ?? 'webinar',
            'level' => $data['level'] ?? 'basic',
            'status' => PublishStatus::Draft,
            'host_name' => $data['host_name'] ?? null,
            'host_title' => $data['host_title'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'stream_url' => $data['stream_url'] ?? null,
            'record_url' => $data['record_url'] ?? null,
        ]);

        ActivityLog::record($request->user(), 'academy.created', $session, ['title' => $session->title]);

        return response()->json([
            'data' => (new AcademyResource($session->load('cover')))
                ->withViewer(['registered' => true, 'can_watch' => true]),
        ], 201);
    }

    public function update(Request $request, AcademySession $session): JsonResponse
    {
        abort_unless($request->user()->can('update', $session), 403);

        $data = $this->validateSession($request, creating: false);

        $session->fill(array_filter([
            'cover_media_id' => $data['cover_media_id'] ?? null,
            'title' => $data['title'] ?? null,
            'lead' => $data['lead'] ?? null,
            'description' => $data['description'] ?? null,
            'format' => $data['format'] ?? null,
            'level' => $data['level'] ?? null,
            'host_name' => $data['host_name'] ?? null,
            'host_title' => $data['host_title'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? null,
        ], fn ($v) => $v !== null));

        // Эти поля можно именно очистить, поэтому null здесь — значащее значение.
        foreach (['starts_at', 'stream_url', 'record_url', 'takeaways'] as $field) {
            if (array_key_exists($field, $data)) {
                $session->{$field} = $data[$field];
            }
        }

        if (! empty($data['slug']) && $data['slug'] !== $session->slug) {
            $session->slug = Slug::unique(AcademySession::class, $data['slug'], 'lesson', $session->id);
        }

        $session->save();

        ActivityLog::record($request->user(), 'academy.updated', $session, ['title' => $session->title]);

        return response()->json([
            'data' => (new AcademyResource($session->fresh(['cover', 'author'])))
                ->withViewer(['registered' => true, 'can_watch' => true]),
        ]);
    }

    public function move(Request $request, AcademySession $session, string $action): JsonResponse
    {
        $to = match ($action) {
            'publish' => PublishStatus::Published,
            'archive' => PublishStatus::Archived,
            'draft' => PublishStatus::Draft,
            'moderation' => PublishStatus::Moderation,
            default => abort(404),
        };

        $ability = in_array($to, [PublishStatus::Published, PublishStatus::Archived], true) ? 'publish' : 'update';
        abort_unless($request->user()->can($ability, $session), 403);

        if (! $session->status->canMoveTo($to)) {
            return response()->json([
                'message' => "Из статуса «{$session->status->label()}» нельзя перейти в «{$to->label()}».",
                'code' => 'invalid_transition',
            ], 422);
        }

        if ($to === PublishStatus::Published) {
            $problem = $this->publishBlocker($session);

            if ($problem) {
                return response()->json(['message' => $problem, 'code' => 'incomplete'], 422);
            }
        }

        $from = $session->status;
        $session->status = $to;

        if ($to === PublishStatus::Published && ! $session->published_at) {
            $session->published_at = now();
        }

        $session->save();

        ActivityLog::record($request->user(), 'academy.'.$action, $session, [
            'from' => $from->value,
            'to' => $to->value,
            'title' => $session->title,
        ]);

        return response()->json([
            'data' => ['status' => $to->value, 'status_label' => $to->label()],
        ]);
    }

    /** Кто записался на эфир. Персональные данные — только руководителю и выше. */
    public function registrations(Request $request, AcademySession $session): JsonResponse
    {
        abort_unless($request->user()->can('viewRegistrations', $session), 403);

        $rows = $session->registrations()
            ->with('user:id,name,email,email_verified_at')
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (AcademyRegistration $row) => [
                'id' => $row->id,
                'created_at' => $row->created_at->toIso8601String(),
                'reminded_at' => $row->reminded_at?->toIso8601String(),
                'watched_at' => $row->watched_at?->toIso8601String(),
                // Запись удаляется каскадом вместе с пользователем — он есть всегда.
                'user' => [
                    'id' => $row->user->id,
                    'name' => $row->user->name,
                    'email' => $row->user->email,
                    'verified' => $row->user->email_verified_at !== null,
                ],
            ])->all(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    /** Чего не хватает, чтобы опубликовать занятие. */
    private function publishBlocker(AcademySession $session): ?string
    {
        $format = $session->format;

        if ($format->isLive() && blank($session->starts_at)) {
            return 'Укажите дату и время эфира — без них занятие нельзя опубликовать.';
        }

        if ($format === AcademyFormat::Lesson && blank($session->record_url)) {
            return 'У видеоурока должна быть ссылка на запись.';
        }

        if ($format->isLive() && blank($session->stream_url) && blank($session->record_url)) {
            return 'Добавьте ссылку на эфир или на запись: записавшимся нечего будет смотреть.';
        }

        return null;
    }

    private function validateSession(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        $data = $request->validate([
            'title' => [$required, 'string', 'min:3', 'max:200'],
            'slug' => ['nullable', 'string', 'max:200', 'regex:/^[a-z0-9-]+$/'],
            'lead' => ['nullable', 'string', 'max:600'],
            'description' => ['nullable', 'string', 'max:20000'],
            // Приходит списком — ровно в том виде, в каком ресурс его отдаёт.
            'takeaways' => ['nullable', 'array', 'max:20'],
            'takeaways.*' => ['string', 'max:200'],
            'format' => ['nullable', 'in:webinar,stream,lesson'],
            'level' => ['nullable', 'in:basic,medium,advanced'],
            'cover_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'host_name' => ['nullable', 'string', 'max:160'],
            'host_title' => ['nullable', 'string', 'max:160'],
            'starts_at' => ['nullable', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:600'],
            'stream_url' => ['nullable', 'url', 'max:500'],
            'record_url' => ['nullable', 'url', 'max:500'],
        ], [
            'slug.regex' => 'В адресе допустимы только латинские буквы, цифры и дефис.',
            'stream_url.url' => 'Ссылка на эфир должна быть полным адресом, вместе с https://.',
            'record_url.url' => 'Ссылка на запись должна быть полным адресом, вместе с https://.',
        ]);

        /*
         * В базе «чему научимся» лежит текстом по строке на пункт, а наружу
         * ресурс отдаёт список. Приводим входящий список к тому же тексту здесь,
         * чтобы ниже по коду поле было обычной строкой.
         */
        if (array_key_exists('takeaways', $data)) {
            $lines = collect($data['takeaways'] ?? [])
                ->map(fn ($line) => trim((string) $line))
                ->filter()
                ->values();

            $data['takeaways'] = $lines->isEmpty() ? null : $lines->implode("\n");
        }

        return $data;
    }
}
