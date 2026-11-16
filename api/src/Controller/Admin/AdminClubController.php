<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Api\Validation\InputValidator;
use App\Entity\User;
use App\Enum\UserRole;
use App\Security\Attribute\VerifiedEmail;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CONTENT_MANAGER'), VerifiedEmail]
final class AdminClubController
{
    private const KINDS = [
        'video' => 'Видеоурок',
        'masterclass' => 'Мастер-класс',
        'recipe' => 'Рецепт недели',
        'material' => 'Материал',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly InputValidator $validator,
        #[Autowire(env: 'STORAGE_PATH')]
        private readonly string $root,
    ) {
    }

    #[Route('/admin/club', name: 'admin_club_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $rows = $this->db->fetchAllAssociative('SELECT * FROM club_items ORDER BY updated_at DESC');
        foreach ($rows as &$r) {
            $cover = null;
            if (!empty($r['cover_media_id'])) {
                $cover = $this->db->fetchAssociative('SELECT * FROM media WHERE id = ?', [(int) $r['cover_media_id']]);
            }
            $r['cover'] = $cover;
            $r['kind_label'] = self::KINDS[$r['kind']] ?? $r['kind'];
            $r['has_video'] = !empty($r['video_path']);
        }

        return new JsonResponse(['data' => $rows]);
    }

    #[Route('/admin/club/{id}', name: 'admin_club_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $item = $this->findItem($id);
        $cover = null;
        if (!empty($item['cover_media_id'])) {
            $cover = $this->db->fetchAssociative('SELECT * FROM media WHERE id = ?', [(int) $item['cover_media_id']]);
        }
        $item['cover'] = $cover;
        $item['kind_label'] = self::KINDS[$item['kind']] ?? $item['kind'];
        $item['has_video'] = !empty($item['video_path']);
        if (isset($item['data']) && \is_string($item['data'])) {
            $item['data'] = json_decode($item['data'], true);
        }

        return new JsonResponse(['data' => $item]);
    }

    #[Route('/admin/club', name: 'admin_club_store', methods: ['POST'])]
    public function store(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$user->getRole()->atLeast(UserRole::ContentManager)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }
        $data = $this->validateClub($request, true);

        $slug = !empty($data['slug']) ? $this->uniqueSlug($data['slug']) : $this->uniqueSlug($data['title']);

        $this->db->insert('club_items', [
            'slug' => $slug,
            'kind' => $data['kind'],
            'title' => $data['title'],
            'lead' => $data['lead'] ?? null,
            'body' => $data['body'] ?? null,
            'chef_name' => $data['chef_name'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'cover_media_id' => $data['cover_media_id'] ?? null,
            'data' => isset($data['data']) ? json_encode($data['data']) : null,
            'is_published' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->db->lastInsertId();

        return $this->show($id);
    }

    #[Route('/admin/club/{id}', name: 'admin_club_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $item = $this->findItem($id);
        if (!$user->getRole()->atLeast(UserRole::ContentManager)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }
        $data = $this->validateClub($request, false);

        $fields = [];
        $params = [];
        $map = [
            'kind' => 'kind',
            'title' => 'title',
            'lead' => 'lead',
            'body' => 'body',
            'chef_name' => 'chef_name',
            'duration_minutes' => 'duration_minutes',
            'cover_media_id' => 'cover_media_id',
            'is_published' => 'is_published',
        ];
        foreach ($map as $k => $col) {
            if (\array_key_exists($k, $data)) {
                $fields[] = "$col = ?";
                $params[] = 'is_published' === $k ? (int) $data[$k] : $data[$k];
            }
        }
        if (\array_key_exists('data', $data)) {
            $fields[] = 'data = ?';
            $params[] = null === $data['data'] ? null : json_encode($data['data']);
        }
        if (!empty($data['slug']) && $data['slug'] !== $item['slug']) {
            $fields[] = 'slug = ?';
            $params[] = $this->uniqueSlug($data['slug']);
        }
        if (\array_key_exists('is_published', $data) && $data['is_published'] && empty($item['published_at'])) {
            $fields[] = 'published_at = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        if ($fields) {
            $fields[] = 'updated_at = ?';
            $params[] = date('Y-m-d H:i:s');
            $params[] = $id;
            $this->db->executeStatement('UPDATE club_items SET '.implode(', ', $fields).' WHERE id = ?', $params);
        }

        return $this->show($id);
    }

    #[Route('/admin/club/{id}/video', name: 'admin_club_upload_video', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function uploadVideo(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$user->getRole()->atLeast(UserRole::ContentManager)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }
        $item = $this->findItem($id);

        $file = $request->files->get('file');
        if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            return new JsonResponse(['message' => 'Файл не передан.'], 422);
        }
        $mime = $file->getMimeType();
        if (!\in_array($mime, ['video/mp4', 'video/webm'], true)) {
            return new JsonResponse(['message' => 'Только MP4 или WebM.'], 422);
        }
        if ($file->getSize() > 512000 * 1024) {
            return new JsonResponse(['message' => 'Файл слишком большой.'], 422);
        }

        $ext = 'video/webm' === $mime ? 'webm' : 'mp4';
        $name = bin2hex(random_bytes(16)).'.'.$ext;
        $targetDir = rtrim($this->root, '/').'/private/club';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        $file->move($targetDir, $name);
        $path = 'club/'.$name;

        // удалить старый
        if (!empty($item['video_path']) && $item['video_path'] !== $path) {
            $old = rtrim($this->root, '/').'/private/'.$item['video_path'];
            if (is_file($old)) {
                @unlink($old);
            }
        }

        $this->db->update('club_items', [
            'video_disk' => 'local',
            'video_path' => $path,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        return $this->show($id);
    }

    /** @return array<string, mixed> */
    private function findItem(int $id): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM club_items WHERE id = ?', [$id]);
        if (!$row) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }

        return $row;
    }

    private function uniqueSlug(string $source): string
    {
        $base = \App\Support\Slug::make($source) ?: 'material';
        $slug = $base;
        $i = 2;
        while ((int) $this->db->fetchOne('SELECT COUNT(*) FROM club_items WHERE slug = ?', [$slug]) > 0) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** @return array<string, mixed> */
    private function validateClub(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $this->validator->validate($request, [
            'kind' => $required.'|in:video,masterclass,recipe,material',
            'title' => $required.'|string|max:200',
            'slug' => 'nullable|string|max:160',
            'lead' => 'nullable|string|max:400',
            'body' => 'nullable|string|max:100000',
            'chef_name' => 'nullable|string|max:160',
            'duration_minutes' => 'nullable|integer|min:1|max:1000',
            'cover_media_id' => 'nullable|integer',
            'data' => 'nullable',
            'is_published' => 'nullable|boolean',
        ]);
    }
}
