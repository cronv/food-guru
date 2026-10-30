<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Лимит писем со ссылками: на адрес, а не на весь IP. */
class MailLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withHeader('Origin', 'http://localhost:4200');
    }

    public function test_чужие_попытки_с_того_же_ip_не_блокируют_вход_другому(): void
    {
        // Кто-то с того же IP шесть раз запросил письма на разные адреса.
        foreach (range(1, 6) as $i) {
            $this->postJson('/api/v1/auth/register', ['email' => "spam{$i}@example.com", 'name' => 'Спам']);
        }

        $admin = $this->user(UserRole::Admin);

        $this->postJson('/api/v1/auth/login', ['email' => $admin->email])->assertStatus(202);
    }

    public function test_один_адрес_ограничен_и_ответ_говорит_сколько_ждать(): void
    {
        $user = $this->user();

        foreach (range(1, 5) as $i) {
            $this->travel(2)->minutes(); // минимальный интервал между письмами — минута
            $this->postJson('/api/v1/auth/login', ['email' => $user->email])->assertStatus(202);
        }

        $this->travel(2)->minutes();
        $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email])->assertStatus(429);
        $this->assertNotNull($response->headers->get('Retry-After'));
    }
}
