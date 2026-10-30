<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaService
{
    /** Сохраняет загруженный файл и заводит запись в медиатеке. */
    public function store(UploadedFile $file, ?User $uploader, ?string $alt = null): Media
    {
        $name = Str::uuid()->toString().'.'.$this->safeExtension($file);
        $path = $file->storeAs('uploads/'.now()->format('Y/m'), $name, 'public');

        [$width, $height] = $this->dimensions($file);

        return Media::create([
            'uploaded_by' => $uploader?->id,
            'path' => $path,
            'disk' => 'public',
            'width' => $width,
            'height' => $height,
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
            'alt' => $alt,
        ]);
    }

    /**
     * Расширение берём из содержимого файла, а не из его имени.
     *
     * Имя целиком под контролем отправителя, а правило mimes проверяет тип
     * по содержимому и на имя не смотрит. Поэтому настоящий PNG, названный
     * «photo.php», проходит проверку и ложится в публичную папку под этим
     * расширением — дальше его подхватывает PHP-FPM. Если к PNG дописан код,
     * это исполнение кода на сервере.
     *
     * Незнакомый тип получает .bin: такой файл браузер скачает, но никто
     * не выполнит.
     */
    private function safeExtension(UploadedFile $file): string
    {
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'svg', 'mp4', 'webm', 'mov', 'pdf'];

        $guessed = strtolower((string) $file->guessExtension());

        return in_array($guessed, $allowed, true) ? $guessed : 'bin';
    }

    public function delete(Media $media): void
    {
        Storage::disk($media->disk)->delete($media->path);
        $media->delete();
    }

    /**
     * Размеры читаем без GD: getimagesize есть всегда.
     * Если файл не картинка — оставляем null, это не повод валить загрузку.
     */
    private function dimensions(UploadedFile $file): array
    {
        $info = @getimagesize($file->getRealPath());

        return $info ? [$info[0], $info[1]] : [null, null];
    }
}
