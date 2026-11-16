<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\UserRole;
use App\Security\Attribute\VerifiedEmail;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CONTENT_MANAGER'), VerifiedEmail]
final class DashboardController
{
    public function __construct(private readonly Connection $db)
    {
    }

    #[Route('/admin/stats', name: 'admin_stats', methods: ['GET'])]
    public function stats(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mine = !$user->getRole()->atLeast(UserRole::EditorLead);
        $authorFilter = $mine ? ' AND author_id = '.$user->getId() : '';

        $weekAgo = (new \DateTimeImmutable('-7 days'))->format('Y-m-d H:i:s');

        $published = (int) $this->db->fetchOne("SELECT COUNT(*) FROM recipes WHERE status = 'published'$authorFilter");
        $publishedWeek = (int) $this->db->fetchOne("SELECT COUNT(*) FROM recipes WHERE status = 'published' AND published_at >= ?$authorFilter", [$weekAgo]);
        $moderation = (int) $this->db->fetchOne("SELECT COUNT(*) FROM recipes WHERE status = 'moderation'$authorFilter");
        $drafts = (int) $this->db->fetchOne("SELECT COUNT(*) FROM recipes WHERE status = 'draft'$authorFilter");
        $rejected = (int) $this->db->fetchOne("SELECT COUNT(*) FROM recipes WHERE status = 'rejected'$authorFilter");

        $totalUsers = (int) $this->db->fetchOne('SELECT COUNT(*) FROM users');
        $usersWeek = (int) $this->db->fetchOne('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$weekAgo]);
        $verified = (int) $this->db->fetchOne('SELECT COUNT(*) FROM users WHERE email_verified_at IS NOT NULL');
        $verifiedShare = $totalUsers > 0 ? (int) round($verified / $totalUsers * 100) : null;
        $unverified = (int) $this->db->fetchOne('SELECT COUNT(*) FROM users WHERE email_verified_at IS NULL');
        $reviewsWeek = (int) $this->db->fetchOne('SELECT COUNT(*) FROM reviews WHERE created_at >= ?', [$weekAgo]);

        $contestsLive = (int) $this->db->fetchOne("SELECT COUNT(*) FROM contests WHERE status = 'published' AND (results_at IS NULL OR results_at > NOW())");
        $entriesPending = (int) $this->db->fetchOne("SELECT COUNT(*) FROM contest_entries WHERE status = 'pending'");
        $academyUpcoming = (int) $this->db->fetchOne("SELECT COUNT(*) FROM academy_sessions WHERE status = 'published' AND starts_at IS NOT NULL AND starts_at >= NOW()");
        $academyDrafts = (int) $this->db->fetchOne("SELECT COUNT(*) FROM academy_sessions WHERE status = 'draft'");
        $journalPublished = (int) $this->db->fetchOne("SELECT COUNT(*) FROM journal_issues WHERE status = 'published'");
        $journalDrafts = (int) $this->db->fetchOne("SELECT COUNT(*) FROM journal_issues WHERE status IN ('draft','moderation')");

        // moderation queue
        $queue = $this->db->fetchAllAssociative(
            "SELECT r.*, u.name AS author_name FROM recipes r LEFT JOIN users u ON u.id = r.author_id WHERE r.status = 'moderation'$authorFilter ORDER BY r.updated_at ASC LIMIT 8"
        );

        return new JsonResponse(['data' => [
            'published' => $published,
            'published_week' => $publishedWeek,
            'moderation' => $moderation,
            'moderation_stale' => 0,
            'drafts' => $drafts,
            'rejected' => $rejected,
            'users_total' => $totalUsers,
            'users_week' => $usersWeek,
            'verified_share' => $verifiedShare,
            'unverified' => $unverified,
            'reviews_week' => $reviewsWeek,
            'queue' => $queue,
            'contests_live' => $contestsLive,
            'contest_entries_pending' => $entriesPending,
            'academy_upcoming' => $academyUpcoming,
            'academy_drafts' => $academyDrafts,
            'journal_published' => $journalPublished,
            'journal_drafts' => $journalDrafts,
        ]]);
    }

    #[Route('/admin/activity', name: 'admin_activity', methods: ['GET'])]
    public function activity(#[CurrentUser] User $user): JsonResponse
    {
        // Как viewActivity в UserPolicy: журнал действий — только администратору.
        if (UserRole::Admin !== $user->getRole()) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT al.*, u.name AS actor_name FROM activity_log al LEFT JOIN users u ON u.id = al.actor_id ORDER BY al.created_at DESC LIMIT 30'
        );
        $data = array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'action' => $r['action'],
            'subject_type' => $r['subject_type'],
            'subject_id' => $r['subject_id'],
            'payload' => $r['payload'] ? json_decode($r['payload'], true) : null,
            'created_at' => $r['created_at'],
            'actor' => $r['actor_name'] ? ['id' => (int) $r['actor_id'], 'name' => $r['actor_name']] : null,
        ], $rows);

        return new JsonResponse(['data' => $data]);
    }
}
