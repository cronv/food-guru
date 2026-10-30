<?php

namespace Tests\Feature;

use App\Enums\AcademyFormat;
use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Models\AcademySession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Главное правило раздела: смотреть можно только по записи.
 * Ссылки не «скрыты стилями», их просто нет в ответе.
 */
class AcademyAccessTest extends TestCase
{
    use RefreshDatabase;

    // Не session(): так называется публичный метод TestCase для данных сессии.
    private function academySession(array $overrides = []): AcademySession
    {
        return AcademySession::create(array_merge([
            'slug' => 'nozh-i-doska',
            'title' => 'Нож и доска',
            'format' => AcademyFormat::Lesson,
            'status' => PublishStatus::Published,
            'published_at' => now()->subWeek(),
            'record_url' => 'https://example.com/record/1',
            'duration_minutes' => 40,
        ], $overrides));
    }

    public function test_гость_видит_занятие_но_не_ссылку_на_запись(): void
    {
        $session = $this->academySession();

        $response = $this->getJson("/api/v1/academy/{$session->slug}")->assertOk();

        $response->assertJsonPath('data.title', 'Нож и доска');
        $response->assertJsonMissingPath('data.record_url');
        $response->assertJsonPath('data.viewer.can_watch', false);
        $response->assertJsonPath('data.viewer.registered', false);
    }

    public function test_авторизованный_без_записи_ссылку_не_получает(): void
    {
        $session = $this->academySession();

        $this->actingAs($this->user())
            ->getJson("/api/v1/academy/{$session->slug}")
            ->assertOk()
            ->assertJsonMissingPath('data.record_url');
    }

    public function test_после_записи_ссылка_появляется(): void
    {
        $session = $this->academySession();
        $user = $this->user();

        $this->actingAs($user)
            ->putJson("/api/v1/academy/{$session->slug}/registration")
            ->assertOk()
            ->assertJsonPath('data.viewer.registered', true);

        $this->actingAs($user)
            ->getJson("/api/v1/academy/{$session->slug}")
            ->assertOk()
            ->assertJsonPath('data.record_url', 'https://example.com/record/1');

        $this->assertSame(1, $session->fresh()->registrations_count);
    }

    public function test_повторная_запись_не_удваивает_счётчик(): void
    {
        $session = $this->academySession();
        $user = $this->user();

        $this->actingAs($user)->putJson("/api/v1/academy/{$session->slug}/registration")->assertOk();
        $this->actingAs($user)->putJson("/api/v1/academy/{$session->slug}/registration")->assertOk();

        $this->assertSame(1, $session->fresh()->registrations_count);
    }

    public function test_отмена_записи_убирает_ссылку_и_уменьшает_счётчик(): void
    {
        $session = $this->academySession();
        $user = $this->user();

        $this->actingAs($user)->putJson("/api/v1/academy/{$session->slug}/registration")->assertOk();
        $this->actingAs($user)->deleteJson("/api/v1/academy/{$session->slug}/registration")->assertOk();

        $this->assertSame(0, $session->fresh()->registrations_count);

        $this->actingAs($user)
            ->getJson("/api/v1/academy/{$session->slug}")
            ->assertJsonMissingPath('data.record_url');
    }

    public function test_редакция_видит_ссылки_без_записи(): void
    {
        $session = $this->academySession();

        $this->actingAs($this->user(UserRole::ContentManager))
            ->getJson("/api/v1/academy/{$session->slug}")
            ->assertOk()
            ->assertJsonPath('data.record_url', 'https://example.com/record/1');
    }

    public function test_ссылка_на_эфир_отдаётся_только_пока_эфир_идёт(): void
    {
        $user = $this->user();

        $upcoming = $this->academySession([
            'slug' => 'efir-zavtra',
            'format' => AcademyFormat::Stream,
            'starts_at' => now()->addDay(),
            'record_url' => null,
            'stream_url' => 'https://example.com/live/1',
            'duration_minutes' => 60,
        ]);

        $this->actingAs($user)->putJson("/api/v1/academy/{$upcoming->slug}/registration")->assertOk();

        $this->actingAs($user)
            ->getJson("/api/v1/academy/{$upcoming->slug}")
            ->assertOk()
            ->assertJsonMissingPath('data.stream_url');

        // Переносим старт в прошлое — эфир идёт прямо сейчас.
        $upcoming->forceFill(['starts_at' => now()->subMinutes(10)])->save();

        $this->actingAs($user)
            ->getJson("/api/v1/academy/{$upcoming->slug}")
            ->assertOk()
            ->assertJsonPath('data.stream_url', 'https://example.com/live/1');
    }

    public function test_черновик_не_виден_посторонним(): void
    {
        $draft = $this->academySession(['slug' => 'chernovik', 'status' => PublishStatus::Draft, 'published_at' => null]);

        $this->getJson("/api/v1/academy/{$draft->slug}")->assertNotFound();
        $this->actingAs($this->user())->getJson("/api/v1/academy/{$draft->slug}")->assertNotFound();
    }

    public function test_список_записавшихся_закрыт_от_контент_менеджера(): void
    {
        $session = $this->academySession();

        $this->actingAs($this->user(UserRole::ContentManager))
            ->getJson("/api/v1/admin/academy/{$session->id}/registrations")
            ->assertForbidden();

        $this->actingAs($this->user(UserRole::EditorLead))
            ->getJson("/api/v1/admin/academy/{$session->id}/registrations")
            ->assertOk();
    }
}
