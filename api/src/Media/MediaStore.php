<?php

declare(strict_types=1);

namespace App\Media;

use App\Catalog\PublishedRecipes;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Загрузка файлов в медиатеку.
 * Файлы ложатся на публичный диск public
 * (storage/app/public/uploads/ГГГГ/ММ), оттуда их отдаёт nginx по /storage/.
 */
final class MediaStore
{
    private const ALLOWED = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'svg', 'mp4', 'webm', 'mov', 'pdf'];

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly MediaUrls $urls,
        #[Autowire(env: 'STORAGE_PATH')]
        private readonly string $root,
    ) {
    }

    /** @return array{id: int, url: string, path: string} */
    public function store(UploadedFile $file, ?int $uploaderId, ?string $alt = null): array
    {
        $now = $this->clock->now();
        $dir = 'uploads/'.$now->format('Y/m');
        $name = Uuid::v4()->toRfc4122().'.'.self::safeExtension($file);
        $info = @getimagesize((string) $file->getRealPath());
        $size = $file->getSize();
        $mime = $file->getMimeType();

        $file->move(rtrim($this->root, '/').'/public/'.$dir, $name);
        $path = $dir.'/'.$name;

        $this->db->insert('media', [
            'uploaded_by' => $uploaderId,
            'path' => $path,
            'disk' => 'public',
            'width' => false === $info ? null : $info[0],
            'height' => false === $info ? null : $info[1],
            'size' => false === $size ? null : $size,
            'mime' => $mime,
            'alt' => $alt,
            'created_at' => PublishedRecipes::now($now),
            'updated_at' => PublishedRecipes::now($now),
        ]);

        return ['id' => (int) $this->db->lastInsertId(), 'url' => $this->urls->url($path), 'path' => $path];
    }

    /**
     * Расширение — из содержимого файла, а не из имени. Имя целиком под контролем
     * отправителя: настоящий PNG с именем «photo.php» иначе лёг бы в публичную
     * папку как .php, и nginx отдал бы его PHP-FPM. Незнакомый тип — .bin.
     */
    public static function safeExtension(UploadedFile $file): string
    {
        $guessed = strtolower((string) $file->guessExtension());

        return \in_array($guessed, self::ALLOWED, true) ? $guessed : 'bin';
    }
}
