<?php

declare(strict_types=1);

namespace App\Admin;

use App\Media\MediaStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/**
 * PDF выпуска журнала (сервис JournalFileService). Файл лежит на закрытом
 * диске (storage/app/private/journal), обложку рисует pdftoppm из первой страницы.
 * Утилиты может не быть или рисование может упасть — тогда выпуск остаётся без
 * обложки, а загрузка файла не падает.
 */
final class JournalFiles
{
    private const DIRECTORY = 'journal';

    public function __construct(
        private readonly Connection $db,
        private readonly MediaStore $media,
        #[Autowire(env: 'STORAGE_PATH')]
        private readonly string $root,
    ) {
    }

    /** @param array<string, mixed> $issue строка journal_issues до загрузки */
    public function attach(array $issue, UploadedFile $file, int $uploaderId): void
    {
        $this->forget($issue);

        $name = Uuid::v4()->toRfc4122().'.pdf';
        $originalName = $file->getClientOriginalName();
        $size = $file->getSize();
        $directory = $this->directory();
        $file->move($directory, $name);
        $full = $directory.'/'.$name;

        $issueId = (int) $issue['id'];
        $this->db->update('journal_issues', [
            'file_path' => self::DIRECTORY.'/'.$name,
            'file_disk' => 'local',
            'file_name' => $originalName,
            'file_size' => false === $size ? null : $size,
            'pages' => $this->countPages($full),
        ], ['id' => $issueId]);

        if (empty($issue['cover_media_id'])) {
            $cover = $this->renderCover($issueId, $uploaderId);
            if (null !== $cover) {
                $this->db->update('journal_issues', ['cover_media_id' => $cover], ['id' => $issueId]);
            }
        }
    }

    /**
     * Удаляет файл выпуска. Обложку не трогает: её мог загрузить редактор.
     *
     * @param array<string, mixed> $issue
     */
    public function forget(array $issue): void
    {
        if (!empty($issue['file_path'])) {
            $full = $this->root.'/private/'.$issue['file_path'];
            if (is_file($full)) {
                unlink($full);
            }
        }

        $this->db->update('journal_issues', [
            'file_path' => null,
            'file_name' => null,
            'file_size' => null,
            'pages' => null,
        ], ['id' => (int) $issue['id']]);
    }

    /** Рисует обложку из первой страницы и заводит её в медиатеке. null — нарисовать не удалось. */
    public function renderCover(int $issueId, int $uploaderId): ?int
    {
        $issue = $this->db->fetchAssociative('SELECT title, file_path FROM journal_issues WHERE id = ?', [$issueId]);
        if (false === $issue || empty($issue['file_path'])) {
            return null;
        }

        $source = $this->root.'/private/'.$issue['file_path'];
        $prefix = sys_get_temp_dir().'/journal-cover-'.Uuid::v4()->toRfc4122();
        $target = $prefix.'.png';

        try {
            // -singlefile заставляет pdftoppm назвать файл ровно <prefix>.png.
            $process = new Process([
                'pdftoppm', '-singlefile', '-png',
                '-f', '1', '-l', '1',
                '-scale-to-x', '900', '-scale-to-y', '-1',
                $source, $prefix,
            ], timeout: 30);
            $process->run();

            if (!$process->isSuccessful() || !is_file($target) || 0 === filesize($target)) {
                return null;
            }

            $upload = new UploadedFile($target, 'cover.png', 'image/png', null, true);

            return $this->media->store($upload, $uploaderId, $issue['title'].' — обложка выпуска')['id'];
        } catch (\Throwable) {
            return null;
        } finally {
            if (is_file($target)) {
                unlink($target);
            }
        }
    }

    private function countPages(string $path): ?int
    {
        try {
            $process = new Process(['pdfinfo', $path], timeout: 30);
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (!$process->isSuccessful()) {
            return null;
        }

        return preg_match('/^Pages:\s+(\d+)/m', $process->getOutput(), $m) ? (int) $m[1] : null;
    }

    private function directory(): string
    {
        $directory = $this->root.'/private/'.self::DIRECTORY;
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $directory;
    }
}
