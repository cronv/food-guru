<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\EntryStatus;
use App\Enums\PublishStatus;
use App\Enums\RecipeStatus;
use App\Enums\UserRole;
use App\Http\Resources\RecipeCardResource;
use App\Models\AcademySession;
use App\Models\ActivityLog;
use App\Models\Contest;
use App\Models\ContestEntry;
use App\Models\JournalIssue;
use App\Models\Recipe;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DashboardController
{
    public function stats(Request $request): JsonResponse
    {
        $mine = ! $request->user()->atLeast(UserRole::EditorLead);
        $scope = fn () => Recipe::query()->when($mine, fn ($q) => $q->where('author_id', $request->user()->id));

        $weekAgo = now()->subWeek();

        $moderation = $scope()->where('status', RecipeStatus::Moderation)
            ->with(['author', 'cover'])
            ->oldest('updated_at')
            ->take(8)
            ->get();

        $stale = (clone $moderation)->filter(fn ($r) => $r->updated_at->lt(now()->subDays(3)))->count();

        $verifiedShare = null;
        $totalUsers = User::count();
        if ($totalUsers > 0) {
            $verifiedShare = (int) round(User::whereNotNull('email_verified_at')->count() / $totalUsers * 100);
        }

        return response()->json([
            'data' => [
                'published' => $scope()->where('status', RecipeStatus::Published)->count(),
                'published_week' => $scope()->where('status', RecipeStatus::Published)
                    ->where('published_at', '>=', $weekAgo)->count(),
                'moderation' => $scope()->where('status', RecipeStatus::Moderation)->count(),
                'moderation_stale' => $stale,
                'drafts' => $scope()->where('status', RecipeStatus::Draft)->count(),
                'rejected' => $scope()->where('status', RecipeStatus::Rejected)->count(),
                'users_total' => $totalUsers,
                'users_week' => User::where('created_at', '>=', $weekAgo)->count(),
                'verified_share' => $verifiedShare,
                'unverified' => User::whereNull('email_verified_at')->count(),
                'reviews_week' => Review::where('created_at', '>=', $weekAgo)->count(),
                'queue' => RecipeCardResource::collection($moderation),

                // Конкурсы и академия: показываем то, что требует действия.
                'contests_live' => Contest::published()
                    ->where(function ($q) {
                        $q->whereNull('results_at')->orWhere('results_at', '>', now());
                    })->count(),
                'contest_entries_pending' => ContestEntry::where('status', EntryStatus::Pending)->count(),
                'academy_upcoming' => AcademySession::published()
                    ->whereNotNull('starts_at')
                    ->where('starts_at', '>=', now())
                    ->count(),
                'academy_drafts' => AcademySession::where('status', PublishStatus::Draft)->count(),

                // Журнал: сколько выпусков вышло и сколько ждут публикации.
                'journal_published' => JournalIssue::published()->count(),
                'journal_drafts' => JournalIssue::whereIn('status', [
                    PublishStatus::Draft, PublishStatus::Moderation,
                ])->count(),
            ],
        ]);
    }

    public function activity(Request $request): AnonymousResourceCollection|JsonResponse
    {
        abort_unless($request->user()->can('viewActivity', User::class), 403);

        $log = ActivityLog::with('actor')
            ->latest('created_at')
            ->paginate(30);

        return response()->json([
            'data' => $log->through(fn ($row) => [
                'id' => $row->id,
                'action' => $row->action,
                'subject_type' => $row->subject_type,
                'subject_id' => $row->subject_id,
                'payload' => $row->payload,
                'created_at' => $row->created_at->toIso8601String(),
                'actor' => $row->actor ? ['id' => $row->actor->id, 'name' => $row->actor->name] : null,
            ])->items(),
            'meta' => [
                'current_page' => $log->currentPage(),
                'last_page' => $log->lastPage(),
                'total' => $log->total(),
            ],
        ]);
    }
}
