<?php

declare(strict_types=1);

namespace App\Media;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Публичные адреса загруженных файлов:
 * PUBLIC_STORAGE_URL + путь. Файлы лежат там же, где и раньше.
 */
final class MediaUrls
{
    public function __construct(
        #[Autowire(env: 'PUBLIC_STORAGE_URL')]
        private readonly string $publicBase,
    ) {
    }

    public function url(string $path): string
    {
        return rtrim($this->publicBase, '/').'/'.ltrim($path, '/');
    }

    /**
     * MediaResource: {id, url, width, height, alt}. Подпись добавляет галерея.
     *
     * @param array<string, mixed> $row строка таблицы media
     *
     * @return array<string, mixed>
     */
    public function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'url' => $this->url((string) $row['path']),
            'width' => null === $row['width'] ? null : (int) $row['width'],
            'height' => null === $row['height'] ? null : (int) $row['height'],
            'alt' => $row['alt'],
        ];
    }
}
