<?php

namespace Tests\Feature;

use App\Enums\EmailTokenType;
use App\Enums\UserRole;
use App\Mail\SecurityNoticeMail;
use App\Services\EmailTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Отключение аккаунта, одноразовость ссылок, смена пароля и почты. */
class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withHeader('Origin', 'http://localhost:4200');
    }

    public function test_отключённый_аккаунт_не_работает_в_старой_сессии(): void
    {
        $user = $this->user();
        $this->actingAs($user)->getJson('/api/v1/me')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user->fresh())->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('code', 'account_disabled');
        $this->actingAs($user->fresh())->getJson('/api/v1/me/wallet')->assertForbidden();
    }

    public function test_отключение_админом_обнуляет_запомнить_меня(): void
    {
        $user = $this->user();
        $user->setRememberToken('old-token');
        $user->save();

        $this->actingAs($this->user(UserRole::Admin))->patchJson("/api/v1/admin/users/{$user->id}", ['is_active' => false])->assertOk();

        $this->assertNotSame('old-token', $user->fresh()->getRememberToken());
    }

    public function test_ссылку_входа_нельзя_использовать_дважды(): void
    {
        $user = $this->user();
        $tokens = app(EmailTokenService::class);
        $plain = $tokens->issue($user, EmailTokenType::Login);

        $token = $tokens->find($plain, EmailTokenType::Login);
        $this->assertTrue($tokens->consume($token));
        // Второй запрос с тем же уже найденным токеном (гонка) — отказ.
        $this->assertFalse($tokens->consume($token));

        $this->postJson('/api/v1/auth/login/confirm', ['token' => $plain])->assertStatus(410);
    }

    public function test_смена_пароля_завершает_другие_сессии_и_пишет_письмо(): void
    {
        $user = $this->user();
        $user->setPassword('old-pass-1');
        \DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($user)->putJson('/api/v1/me/password', [
            'current_password' => 'old-pass-1', 'password' => 'new-pass-2', 'password_confirmation' => 'new-pass-2',
        ])->assertOk();

        $this->assertSame(0, \DB::table('sessions')->where('id', 'other-device')->count());
        Mail::assertQueued(SecurityNoticeMail::class, fn ($m) => $m->hasTo($user->email));
    }

    public function test_смена_почты_с_паролем_требует_пароль_и_уведомляет_старый_адрес(): void
    {
        $user = $this->user();
        $user->setPassword('pass-word-1');

        $this->actingAs($user)->patchJson('/api/v1/me', ['email' => 'thief@example.com'])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->actingAs($user)->patchJson('/api/v1/me', ['email' => 'new@example.com', 'current_password' => 'pass-word-1'])->assertOk();
        Mail::assertQueued(SecurityNoticeMail::class, fn ($m) => $m->hasTo($user->email));
    }

    public function test_повторная_отправка_не_выдаёт_существует_ли_аккаунт(): void
    {
        $user = $this->unverified();

        $first = $this->postJson('/api/v1/auth/resend', ['email' => $user->email])->status();
        $again = $this->postJson('/api/v1/auth/resend', ['email' => $user->email])->status();
        $nobody = $this->postJson('/api/v1/auth/resend', ['email' => 'nobody@example.com'])->status();

        $this->assertSame([202, 202, 202], [$first, $again, $nobody]);
    }

    public function test_слишком_много_тегов_в_фильтре_отклоняется(): void
    {
        $tags = implode('&', array_map(fn ($i) => "tags[]=t{$i}", range(1, 11)));
        $this->getJson('/api/v1/recipes?'.$tags)->assertStatus(422);
    }
}
