<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Http\Resources\JournalIssueCardResource;
use App\Http\Resources\JournalIssueResource;
use App\Models\ActivityLog;
use App\Models\JournalIssue;
use App\Services\JournalFileService;
use App\Support\Slug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class AdminJournalController
{
    public function __construct(private JournalFileService $files) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,moderation,published,archived'],
            'period' => ['nullable', 'string', 'in:week,month'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = JournalIssue::query()->with('cover')->orderByDesc('issue_date')->orderByDesc('id');

        if (! $request->user()->atLeast(UserRole::EditorLead)) {
            $query->where('author_id', $request->user()->id);
        }

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['period'])) {
            $query->where('period', $data['period']);
        }
        if (! empty($data['year'])) {
            $query->where('issue_year', $data['year']);
        }
        if (! empty($data['q'])) {
            $query->where('title', 'like', '%'.$data['q'].'%');
        }

        return JournalIssueCardResource::collection(
            $query->paginate($data['per_page'] ?? 20)->withQueryString()
        );
    }

    public function show(Request $request, JournalIssue $issue): JournalIssueResource
    {
        abort_unless($request->user()->can('view', $issue), 403);

        return new JournalIssueResource($issue->load(['cover', 'author']));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('create', JournalIssue::class), 403);

        $data = $this->validateIssue($request, creating: true);

        $issue = new JournalIssue([
            'author_id' => $request->user()->id,
            'cover_media_id' => $data['cover_media_id'] ?? null,
            'slug' => Slug::unique(JournalIssue::class, $data['slug'] ?? $data['title'], 'issue'),
            'title' => $data['title'],
            'lead' => $data['lead'] ?? null,
            'description' => $data['description'] ?? null,
            'period' => $data['period'] ?? 'month',
            'issue_date' => Carbon::parse($data['issue_date']),
            'status' => PublishStatus::Draft,
        ]);

        $issue->syncPeriodColumns();
        $issue->save();

        ActivityLog::record($request->user(), 'journal.created', $issue, ['title' => $issue->title]);

        return response()->json([
            'data' => new JournalIssueResource($issue->load('cover')),
        ], 201);
    }

    public function update(Request $request, JournalIssue $issue): JsonResponse
    {
        abort_unless($request->user()->can('update', $issue), 403);

        $data = $this->validateIssue($request, creating: false);

        $issue->fill(array_filter([
            'cover_media_id' => $data['cover_media_id'] ?? null,
            'title' => $data['title'] ?? null,
            'lead' => $data['lead'] ?? null,
            'description' => $data['description'] ?? null,
            'period' => $data['period'] ?? null,
        ], fn ($v) => $v !== null));

        if (! empty($data['issue_date'])) {
            $issue->issue_date = Carbon::parse($data['issue_date']);
        }

        // Период или дата могли поменяться — год и месяц пересобираем всегда.
        $issue->syncPeriodColumns();

        if (! empty($data['slug']) && $data['slug'] !== $issue->slug) {
            $issue->slug = Slug::unique(JournalIssue::class, $data['slug'], 'issue', $issue->id);
        }

        $issue->save();

        ActivityLog::record($request->user(), 'journal.updated', $issue, ['title' => $issue->title]);

        return response()->json([
            'data' => new JournalIssueResource($issue->fresh(['cover', 'author'])),
        ]);
    }

    /** Загрузка самого PDF. Отдельным маршрутом: это файл, а не поле формы. */
    public function uploadFile(Request $request, JournalIssue $issue): JsonResponse
    {
        abort_unless($request->user()->can('update', $issue), 403);

        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:51200'],
        ], [
            'file.required' => 'Выберите PDF с гайдом.',
            'file.mimes' => 'Подойдёт только PDF.',
            'file.mimetypes' => 'Файл не похож на PDF — проверьте, что он не переименован.',
            'file.max' => 'Файл должен быть меньше 50 МБ.',
        ]);

        $this->files->attach($issue, $request->file('file'), $request->user());

        ActivityLog::record($request->user(), 'journal.file.uploaded', $issue, [
            'name' => $issue->fresh()->file_name,
        ]);

        return response()->json([
            'data' => new JournalIssueResource($issue->fresh(['cover', 'author'])),
        ]);
    }

    public function deleteFile(Request $request, JournalIssue $issue): JsonResponse
    {
        abort_unless($request->user()->can('update', $issue), 403);

        $this->files->forget($issue);

        ActivityLog::record($request->user(), 'journal.file.removed', $issue);

        return response()->json([
            'data' => new JournalIssueResource($issue->fresh(['cover', 'author'])),
        ]);
    }

    /** Перерисовать обложку из первой страницы PDF. */
    public function renderCover(Request $request, JournalIssue $issue): JsonResponse
    {
        abort_unless($request->user()->can('update', $issue), 403);

        if (! $issue->hasFile()) {
            return response()->json([
                'message' => 'Сначала загрузите PDF — обложку рисовать не из чего.',
                'code' => 'file_missing',
            ], 422);
        }

        $cover = $this->files->renderCover($issue, $request->user());

        if (! $cover) {
            return response()->json([
                'message' => 'Не удалось нарисовать обложку. Загрузите картинку вручную.',
                'code' => 'render_failed',
            ], 422);
        }

        $issue->forceFill(['cover_media_id' => $cover->id])->save();

        return response()->json([
            'data' => new JournalIssueResource($issue->fresh(['cover', 'author'])),
        ]);
    }

    public function move(Request $request, JournalIssue $issue, string $action): JsonResponse
    {
        $to = match ($action) {
            'publish' => PublishStatus::Published,
            'archive' => PublishStatus::Archived,
            'draft' => PublishStatus::Draft,
            'moderation' => PublishStatus::Moderation,
            default => abort(404),
        };

        $ability = in_array($to, [PublishStatus::Published, PublishStatus::Archived], true) ? 'publish' : 'update';
        abort_unless($request->user()->can($ability, $issue), 403);

        if (! $issue->status->canMoveTo($to)) {
            return response()->json([
                'message' => "Из статуса «{$issue->status->label()}» нельзя перейти в «{$to->label()}».",
                'code' => 'invalid_transition',
            ], 422);
        }

        // Выпуск без файла публиковать незачем: скачивать будет нечего.
        if ($to === PublishStatus::Published && ! $issue->hasFile()) {
            return response()->json([
                'message' => 'Загрузите PDF — без него выпуск нечего скачивать.',
                'code' => 'file_missing',
            ], 422);
        }

        $from = $issue->status;
        $issue->status = $to;

        if ($to === PublishStatus::Published && ! $issue->published_at) {
            $issue->published_at = now();
        }

        $issue->save();

        ActivityLog::record($request->user(), 'journal.'.$action, $issue, [
            'from' => $from->value,
            'to' => $to->value,
            'title' => $issue->title,
        ]);

        return response()->json([
            'data' => ['status' => $to->value, 'status_label' => $to->label()],
        ]);
    }

    private function validateIssue(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'title' => [$required, 'string', 'min:3', 'max:200'],
            'slug' => ['nullable', 'string', 'max:200', 'regex:/^[a-z0-9-]+$/'],
            'lead' => ['nullable', 'string', 'max:600'],
            'description' => ['nullable', 'string', 'max:20000'],
            'period' => ['nullable', 'in:week,month'],
            'issue_date' => [$required, 'date'],
            'cover_media_id' => ['nullable', 'integer', 'exists:media,id'],
        ], [
            'slug.regex' => 'В адресе допустимы только латинские буквы, цифры и дефис.',
            'issue_date.required' => 'Укажите неделю или месяц, к которому относится гайд.',
        ]);
    }
}
