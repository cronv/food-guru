<?php

namespace App\Http\Controllers\Api;

use App\Enums\JournalPeriod;
use App\Http\Resources\JournalIssueCardResource;
use App\Http\Resources\JournalIssueResource;
use App\Models\JournalIssue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JournalController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'period' => ['nullable', 'string', 'in:week,month'],
            'page' => ['nullable', 'integer', 'min:1'],
        ], [
            'month.min' => 'Месяц — число от 1 до 12.',
        ]);

        $query = JournalIssue::published()
            ->with('cover')
            ->search($data['q'] ?? null);

        // Фильтр идёт по отдельным колонкам года и месяца — так работает индекс.
        if (! empty($data['year'])) {
            $query->where('issue_year', $data['year']);
        }
        if (! empty($data['month'])) {
            $query->where('issue_month', $data['month']);
        }
        if (! empty($data['period'])) {
            $query->where('period', $data['period']);
        }

        $issues = $query->orderByDesc('issue_date')->orderByDesc('id')->paginate(12);

        return JournalIssueCardResource::collection($issues->withQueryString());
    }

    /**
     * Годы и месяцы, в которых есть выпуски.
     *
     * Отдельный маршрут, а не часть списка: иначе фильтр пересобирался бы
     * под уже выбранный фильтр, и выбранный месяц оставался бы единственным.
     */
    public function periods(): JsonResponse
    {
        $rows = JournalIssue::published()
            ->select('issue_year', 'issue_month', DB::raw('COUNT(*) as total'))
            ->groupBy('issue_year', 'issue_month')
            ->orderByDesc('issue_year')
            ->orderByDesc('issue_month')
            ->get();

        $years = [];

        foreach ($rows as $row) {
            $year = (int) $row->issue_year;

            $years[$year] ??= ['year' => $year, 'count' => 0, 'months' => []];
            $years[$year]['count'] += (int) $row->total;
            $years[$year]['months'][] = [
                'month' => (int) $row->issue_month,
                'label' => JournalPeriod::monthName((int) $row->issue_month),
                'count' => (int) $row->total,
            ];
        }

        return response()->json([
            'data' => array_values($years),
            'meta' => ['total' => JournalIssue::published()->count()],
        ]);
    }

    public function show(Request $request, string $slug): JournalIssueResource
    {
        $issue = JournalIssue::where('slug', $slug)
            ->with(['cover', 'author'])
            ->firstOrFail();

        abort_unless($request->user()?->can('view', $issue) ?? $issue->isPublic(), 404);

        return new JournalIssueResource($issue);
    }

    /**
     * Отдаёт PDF и считает скачивание.
     *
     * Файл лежит на закрытом диске и проходит через PHP намеренно: прямая
     * ссылка на public обошла бы счётчик и осталась бы рабочей после снятия
     * выпуска с публикации.
     */
    public function download(Request $request, string $slug): StreamedResponse
    {
        $issue = JournalIssue::published()->where('slug', $slug)->firstOrFail();

        abort_unless($issue->hasFile(), 404);

        $disk = Storage::disk($issue->file_disk);

        abort_unless($disk->exists($issue->file_path), 404);

        // Счётчик не должен мешать отдаче файла, если с ним что-то не так.
        try {
            $issue->increment('downloads_count');
        } catch (\Throwable $e) {
            report($e);
        }

        return $disk->download($issue->file_path, $issue->downloadName(), [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
