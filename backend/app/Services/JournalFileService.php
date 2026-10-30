<?php

namespace App\Services;

use App\Models\JournalIssue;
use App\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * PDF выпуска журнала: хранение, превью и число страниц.
 *
 * Файл кладётся на закрытый диск, а не в public: иначе прямую ссылку
 * можно было бы разослать мимо маршрута скачивания, и счётчик перестал бы
 * что-либо значить.
 *
 * Обложку редактор может загрузить сам. Если не загрузил, первую страницу
 * рисует pdftoppm. Инструмента может не оказаться — тогда выпуск просто
 * останется без обложки, и это не повод ронять загрузку файла.
 */
class JournalFileService
{
    private const DISK = 'local';

    private const DIRECTORY = 'journal';

    public function __construct(private MediaService $media) {}

    /** Сохраняет PDF и заполняет у выпуска всё, что из файла вычитывается. */
    public function attach(JournalIssue $issue, UploadedFile $file, ?User $uploader = null): void
    {
        $this->forget($issue);

        $name = Str::uuid()->toString().'.pdf';
        $path = $file->storeAs(self::DIRECTORY, $name, self::DISK);

        $issue->forceFill([
            'file_path' => $path,
            'file_disk' => self::DISK,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'pages' => $this->countPages(Storage::disk(self::DISK)->path($path)),
        ])->save();

        if (! $issue->cover_media_id) {
            $cover = $this->renderCover($issue, $uploader);

            if ($cover) {
                $issue->forceFill(['cover_media_id' => $cover->id])->save();
            }
        }
    }

    /** Удаляет файл выпуска. Обложку не трогает: её мог загрузить редактор. */
    public function forget(JournalIssue $issue): void
    {
        if ($issue->hasFile()) {
            Storage::disk($issue->file_disk)->delete($issue->file_path);
        }

        $issue->forceFill([
            'file_path' => null,
            'file_name' => null,
            'file_size' => null,
            'pages' => null,
        ])->save();
    }

    /**
     * Рисует обложку из первой страницы и заводит её в медиатеке.
     * Возвращает null, если нарисовать не удалось по любой причине.
     */
    public function renderCover(JournalIssue $issue, ?User $uploader = null): ?Media
    {
        if (! $issue->hasFile() || ! $this->toolAvailable('pdftoppm')) {
            return null;
        }

        $source = Storage::disk($issue->file_disk)->path($issue->file_path);
        $prefix = sys_get_temp_dir().'/journal-cover-'.Str::uuid();
        $target = $prefix.'.png';

        try {
            /*
             * -singlefile заставляет pdftoppm назвать файл ровно <prefix>.png.
             * Без него он дописывает номер страницы, а ширина суффикса зависит
             * от числа страниц в документе — угадывать имя пришлось бы наугад.
             */
            $this->run([
                'pdftoppm', '-singlefile', '-png',
                '-f', '1', '-l', '1',
                '-scale-to-x', '900', '-scale-to-y', '-1',
                $source, $prefix,
            ]);

            if (! is_file($target) || filesize($target) === 0) {
                return null;
            }

            $upload = new UploadedFile($target, 'cover.png', 'image/png', null, true);

            return $this->media->store($upload, $uploader, $issue->title.' — обложка выпуска');
        } catch (\Throwable $e) {
            // Выпуск без обложки — рабочее состояние, а не авария.
            report($e);

            return null;
        } finally {
            if (is_file($target)) {
                @unlink($target);
            }
        }
    }

    /** Число страниц из pdfinfo. null, если инструмента нет или вывод неожиданный. */
    private function countPages(string $path): ?int
    {
        if (! $this->toolAvailable('pdfinfo')) {
            return null;
        }

        try {
            $output = $this->run(['pdfinfo', $path]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return preg_match('/^Pages:\s+(\d+)/m', $output, $m) ? (int) $m[1] : null;
    }

    private function run(array $command): string
    {
        $process = new Process($command, timeout: 30);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }

    /** Есть ли утилита в PATH. Результат кэшируем на время запроса. */
    private function toolAvailable(string $tool): bool
    {
        static $checked = [];

        if (! array_key_exists($tool, $checked)) {
            $process = new Process(['which', $tool], timeout: 5);
            $process->run();
            $checked[$tool] = $process->isSuccessful();
        }

        return $checked[$tool];
    }
}
