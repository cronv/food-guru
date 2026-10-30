<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\ActivityLog;
use App\Models\RecipeReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Жалобы читателей на рецепты. Разбирает руководитель редакции и выше. */
class AdminReportController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:open,resolved,rejected,all'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $status = $data['status'] ?? 'open';

        $reports = RecipeReport::query()
            ->with(['recipe:id,slug,title,status', 'user:id,name,email', 'resolver:id,name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return response()->json([
            'data' => $reports->getCollection()->map(fn (RecipeReport $r) => [
                'id' => $r->id,
                'reason' => $r->reason,
                'reason_label' => RecipeReport::REASONS[$r->reason] ?? $r->reason,
                'message' => $r->message,
                'status' => $r->status,
                'created_at' => $r->created_at?->toIso8601String(),
                'resolved_at' => $r->resolved_at?->toIso8601String(),
                // Рецепт и автор есть всегда: внешние ключи жалобы удаляются каскадом вместе с ними.
                'recipe' => ['id' => $r->recipe->id, 'slug' => $r->recipe->slug, 'title' => $r->recipe->title],
                'author' => ['id' => $r->user->id, 'name' => $r->user->name, 'email' => $r->user->email],
                'resolver' => $r->resolver?->name,
            ]),
            'meta' => [
                'current_page' => $reports->currentPage(),
                'last_page' => $reports->lastPage(),
                'per_page' => $reports->perPage(),
                'total' => $reports->total(),
                'open_total' => RecipeReport::where('status', 'open')->count(),
            ],
        ]);
    }

    /** Закрыть жалобу: resolved — исправили, rejected — жалоба не подтвердилась. */
    public function resolve(Request $request, RecipeReport $report): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:resolved,rejected']]);

        $report->forceFill([
            'status' => $data['status'],
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ])->save();

        ActivityLog::record($request->user(), 'report.'.$data['status'], $report->recipe, [
            'report_id' => $report->id,
        ]);

        return response()->json(['data' => ['id' => $report->id, 'status' => $report->status]]);
    }
}
