<?php

declare(strict_types=1);

namespace App\Seed;

use App\Catalog\PublishedRecipes;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Файлы, медиатека и даты для демо-данных. Ассеты читаются из SEED_ASSETS_PATH
 * (в контейнере — ./seed-assets, смонтирован только на чтение). Копии ложатся в
 * STORAGE_PATH так же, как MediaStore: public/ отдаёт nginx, private/ — закрытые
 * файлы (PDF журнала, видео клуба).
 */
final class DemoStore
{
    private const DEFAULT_ASSETS = '/var/www/seed-assets';

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'STORAGE_PATH')]
        private readonly string $storage,
    ) {
    }

    public function asset(string $relative): string
    {
        $root = $_SERVER['SEED_ASSETS_PATH'] ?? $_ENV['SEED_ASSETS_PATH'] ?? (getenv('SEED_ASSETS_PATH') ?: self::DEFAULT_ASSETS);

        return rtrim((string) $root, '/').'/'.$relative;
    }

    /** @return array<mixed> */
    public function json(string $relative): array
    {
        return json_decode((string) file_get_contents($this->asset($relative)), true, flags: \JSON_THROW_ON_ERROR);
    }

    /** Время в UTC, как его хранит приложение: «-10 days», время — «19:00». */
    public function at(string $modify = '', string $time = ''): string
    {
        return PublishedRecipes::now($this->moment($modify, $time));
    }

    /** Дата в UTC: для дней захода и даты последнего визита. */
    public function day(string $modify = ''): string
    {
        return $this->moment($modify)->format('Y-m-d');
    }

    public function uploaderId(): int
    {
        $id = $this->db->fetchOne("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")
            ?: $this->db->fetchOne('SELECT id FROM users ORDER BY id LIMIT 1');

        return (int) $id;
    }

    public function user(string $name, string $email, string $role, ?string $verifiedAt = null): int
    {
        $now = $this->at();
        $this->db->insert('users', [
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'is_active' => 1,
            'email_verified_at' => $verifiedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** Текстовый файл в public/ — то, что отдаёт nginx по /storage/. */
    public function publish(string $relative, string $content): void
    {
        file_put_contents($this->target('public', $relative), $content);
    }

    public function publishFile(string $source, string $relative): void
    {
        copy($source, $this->target('public', $relative));
    }

    /** Закрытый файл: лежит в private/, отдаётся только через приложение. */
    public function privateFile(string $source, string $relative): void
    {
        copy($source, $this->target('private', $relative));
    }

    public function svgMedia(string $path, string $svg, int $width, int $height, string $alt, int $uploaderId): int
    {
        $this->publish($path, $svg);

        return $this->mediaRow($path, $uploaderId, $width, $height, \strlen($svg), 'image/svg+xml', $alt);
    }

    public function mediaRow(string $path, int $uploaderId, int $width, int $height, int $size, string $mime, string $alt): int
    {
        $now = $this->at();
        $this->db->insert('media', [
            'uploaded_by' => $uploaderId,
            'path' => $path,
            'disk' => 'public',
            'width' => $width,
            'height' => $height,
            'size' => $size,
            'mime' => $mime,
            'alt' => $alt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function target(string $disk, string $relative): string
    {
        $path = rtrim($this->storage, '/').'/'.$disk.'/'.$relative;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0775, true);
        }

        return $path;
    }

    private function moment(string $modify, string $time = ''): \DateTimeImmutable
    {
        $moment = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        if ('' !== $modify) {
            $moment = $moment->modify($modify);
        }
        if ('' !== $time) {
            [$hour, $minute] = array_map('intval', explode(':', $time));
            $moment = $moment->setTime($hour, $minute);
        }

        return $moment;
    }
}
