<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Загруженный файл не должен попадать в публичную папку с исполняемым
 * расширением. Имя файла задаёт отправитель, правило mimes смотрит только
 * на содержимое — значит, расширение обязано приходить из содержимого.
 */
class UploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    /** Настоящий PNG 1×1, чтобы тип определялся по содержимому, а не по имени. */
    private function png(string $name): UploadedFile
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    public function test_png_с_именем_php_сохраняется_как_png(): void
    {
        Storage::fake('public');

        $media = app(MediaService::class)->store($this->png('evil.php'), null);

        $this->assertStringEndsWith('.png', $media->path);
        $this->assertStringNotContainsString('.php', $media->path);
    }

    public function test_двойное_расширение_не_проходит(): void
    {
        Storage::fake('public');

        $media = app(MediaService::class)->store($this->png('photo.png.phtml'), null);

        $this->assertStringEndsWith('.png', $media->path);
    }

    public function test_имя_файла_не_попадает_в_путь_целиком(): void
    {
        Storage::fake('public');

        $media = app(MediaService::class)->store($this->png('../../../etc/passwd.png'), null);

        $this->assertStringNotContainsString('..', $media->path);
        $this->assertStringNotContainsString('passwd', $media->path);
        $this->assertStringStartsWith('uploads/', $media->path);
    }

    public function test_загрузка_в_медиатеку_требует_прав_редакции(): void
    {
        Storage::fake('public');

        $this->actingAs($this->user())
            ->post('/api/v1/admin/media', ['file' => $this->png('photo.png')])
            ->assertForbidden();
    }

    public function test_редакция_загружает_картинку_успешно(): void
    {
        Storage::fake('public');

        $this->actingAs($this->user(UserRole::ContentManager))
            ->post('/api/v1/admin/media', ['file' => $this->png('photo.png')])
            ->assertCreated()
            ->assertJsonPath('data.alt', null);
    }

    public function test_непонятный_тип_получает_безопасное_расширение(): void
    {
        Storage::fake('public');

        $path = tempnam(sys_get_temp_dir(), 'bin');
        file_put_contents($path, random_bytes(64));
        $file = new UploadedFile($path, 'payload.php', 'application/octet-stream', null, true);

        $media = app(MediaService::class)->store($file, null);

        $this->assertStringEndsWith('.bin', $media->path);
    }
}
