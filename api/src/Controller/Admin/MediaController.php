<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Api\Validation\InputValidator;
use App\Entity\User;
use App\Enum\UserRole;
use App\Security\Attribute\VerifiedEmail;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CONTENT_MANAGER'), VerifiedEmail]
final class MediaController
{
    public function __construct(
        private readonly Connection $db,
        private readonly InputValidator $validator,
        #[Autowire(env: 'STORAGE_PATH')]
        private readonly string $root,
    ) {
    }

    #[Route('/admin/media', name: 'admin_media_index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): JsonResponse
    {
        $where = '';
        $params = [];
        if (!$user->getRole()->atLeast(UserRole::EditorLead)) {
            $where = 'WHERE uploaded_by = ?';
            $params[] = $user->getId();
        }
        $rows = $this->db->fetchAllAssociative(
            "SELECT * FROM media $where ORDER BY id DESC LIMIT 24",
            $params
        );

        return new JsonResponse(['data' => $rows]);
    }

    #[Route('/admin/media', name: 'admin_media_store', methods: ['POST'])]
    public function store(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = $this->validator->validate($request, [
            'file' => 'required|file|image',
            'alt'  => 'nullable|string|max:200',
        ]);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return new JsonResponse(['message' => 'Файл не передан.'], 422);
        }

        // Снимаем метаданные ДО перемещения: после move() временного файла уже нет.
        $size = $file->getSize();
        $mime = $file->getMimeType();

        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $ext = strtolower((string) $file->guessExtension());
        if (!\in_array($ext, $allowed, true)) {
            $ext = 'bin';
        }

        $name = bin2hex(random_bytes(16)).'.'.$ext;
        $dir  = date('Y/m');
        $targetDir = rtrim($this->root, '/').'/public/uploads/'.$dir;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);      // не 0777
        }
        $file->move($targetDir, $name);
        $path = 'uploads/'.$dir.'/'.$name;

        [$width, $height] = [null, null];
        $info = @getimagesize($targetDir.'/'.$name);
        if ($info) {
            [$width, $height] = [$info[0], $info[1]];
        }

        $this->db->insert('media', [
            'uploaded_by' => $user->getId(),
            'path'        => $path,
            'disk'        => 'public',
            'width'       => $width,
            'height'      => $height,
            'size'        => $size,             // снято до move
            'mime'        => $mime,             // снято до move
            'alt'         => $data['alt'] ?? null,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->db->lastInsertId();

        return new JsonResponse(['data' => $this->db->fetchAssociative('SELECT * FROM media WHERE id = ?', [$id])], 201);
    }

    #[Route('/admin/media/{id}', name: 'admin_media_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $media = $this->db->fetchAssociative('SELECT * FROM media WHERE id = ?', [$id]);
        if (!$media) {
            return new JsonResponse(['message' => 'Not found.'], 404);
        }
        if ((int) $media['uploaded_by'] !== $user->getId() && !$user->getRole()->atLeast(UserRole::EditorLead)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        $fullPath = rtrim($this->root, '/').'/public/' . $media['path'];
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
        $this->db->delete('media', ['id' => $id]);

        return new JsonResponse(['data' => ['deleted' => true]]);
    }
}
