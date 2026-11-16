<?php

declare(strict_types=1);

namespace App\Media;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

use function Symfony\Component\String\u;

/**
 * Закрытые файлы (PDF журнала, видео клуба). Лежат там же, где и раньше:
 * диск local — storage/app/private. Папка подключена к
 * контейнеру Symfony только на чтение (docker-compose.yml).
 */
final class PrivateFiles
{
    private const DISKS = ['local' => 'private', 'public' => 'public'];

    public function __construct(
        #[Autowire(env: 'STORAGE_PATH')]
        private readonly string $root,
    ) {
    }

    /** Полный путь к существующему файлу или null. Путь из базы не выпускает за корень диска. */
    public function path(string $disk, string $relative): ?string
    {
        $base = realpath(rtrim($this->root, '/').'/'.(self::DISKS[$disk] ?? throw new \InvalidArgumentException("Неизвестный диск {$disk}")));
        if (false === $base) {
            return null;
        }

        $full = realpath($base.'/'.ltrim($relative, '/'));

        return false !== $full && str_starts_with($full, $base.'/') && is_file($full) ? $full : null;
    }

    /** Скачивание — как Storage::download(): attachment с именем и ASCII-запасным вариантом. */
    public function download(string $path, string $name, string $contentType): BinaryFileResponse
    {
        $response = new BinaryFileResponse($path, 200, ['Content-Type' => $contentType]);
        $fallback = str_replace('%', '', u($name)->ascii()->toString());
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name, $fallback));

        return $response;
    }
}
