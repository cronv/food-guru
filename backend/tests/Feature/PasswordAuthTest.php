<?php

namespace Tests\Feature;

use App\Enums\EmailTokenType;
use App\Mail\VerifyEmailMail;
use App\Models\User;
use App\Services\EmailTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Два способа входа: ссылка из письма и почта с паролем.
 * Пароль появляется после первого перехода по ссылке.
 */
class PasswordAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Сессия подключается только к запросам с адреса из SANCTUM_STATEFUL_DOMAINS,
        // как в браузере. Без Origin вход падает на «Session store not set».
        $this->withHeader('Origin', 'http://localhost:4200');
    }

    public function test_регистрация_шлёт_ссылку_и_пароля_ещё_нет(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/register', ['name' => 'Анна', 'email' => 'anna@example.com'])
            ->assertStatus(202);

        Mail::assertQueued(VerifyEmailMail::class);
        $this->assertNull(User::where('email', 'anna@example.com')->value('password'));
    }

    public function test_после_перехода_по_ссылке_пароля_нет_и_ответ_это_сообщает(): void
    {
        $user = $this->unverified();
        $plain = app(EmailTokenService::class)->issue($user, EmailTokenType::Verify);

        $this->postJson('/api/v1/auth/verify', ['token' => $plain])
            ->assertOk()
            ->assertJsonPath('data.has_password', false)
            ->assertJsonPath('data.email_verified', true);
    }

    public function test_первый_пароль_задаётся_без_текущего(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->putJson('/api/v1/me/password', [
                'password' => 'Borsch2026',
                'password_confirmation' => 'Borsch2026',
            ])
            ->assertOk()
            ->assertJsonPath('data.has_password', true);

        $this->assertTrue(Hash::check('Borsch2026', $user->fresh()->password));
    }

    public function test_пароль_хранится_хешем_и_наружу_не_отдаётся(): void
    {
        $user = $this->user();
        $user->setPassword('Borsch2026');

        $this->assertNotSame('Borsch2026', $user->fresh()->getRawOriginal('password'));

        $this->actingAs($user)->getJson('/api/v1/me')->assertJsonMissingPath('data.password');
    }

    public function test_слабый_пароль_отклоняется(): void
    {
        $user = $this->user();

        foreach (['short1', 'onlyletters', '12345678'] as $weak) {
            $this->actingAs($user)
                ->putJson('/api/v1/me/password', ['password' => $weak, 'password_confirmation' => $weak])
                ->assertStatus(422)
                ->assertJsonValidationErrors('password');
        }
    }

    public function test_несовпавшее_подтверждение_отклоняется(): void
    {
        $this->actingAs($this->user())
            ->putJson('/api/v1/me/password', ['password' => 'Borsch2026', 'password_confirmation' => 'Borsch2027'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_смена_пароля_требует_текущий(): void
    {
        $user = $this->user();
        $user->setPassword('Borsch2026');

        $this->actingAs($user)
            ->putJson('/api/v1/me/password', ['password' => 'Okroshka2026', 'password_confirmation' => 'Okroshka2026'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->actingAs($user)
            ->putJson('/api/v1/me/password', [
                'current_password' => 'wrong-password1',
                'password' => 'Okroshka2026',
                'password_confirmation' => 'Okroshka2026',
            ])
            ->assertStatus(422);

        $this->actingAs($user)
            ->putJson('/api/v1/me/password', [
                'current_password' => 'Borsch2026',
                'password' => 'Okroshka2026',
                'password_confirmation' => 'Okroshka2026',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('Okroshka2026', $user->fresh()->password));
    }

    public function test_гость_пароль_задать_не_может(): void
    {
        $this->putJson('/api/v1/me/password', ['password' => 'Borsch2026', 'password_confirmation' => 'Borsch2026'])
            ->assertUnauthorized();
    }

    public function test_вход_по_почте_и_паролю(): void
    {
        $user = $this->user(attributes: ['email' => 'anna@example.com']);
        $user->setPassword('Borsch2026');

        $this->postJson('/api/v1/auth/login/password', ['email' => 'Anna@Example.com', 'password' => 'Borsch2026'])
            ->assertOk()
            ->assertJsonPath('data.email', 'anna@example.com');

        $this->getJson('/api/v1/me')->assertOk();
    }

    public function test_неверный_пароль_и_неизвестная_почта_дают_одинаковый_ответ(): void
    {
        $user = $this->user(attributes: ['email' => 'anna@example.com']);
        $user->setPassword('Borsch2026');

        $wrong = $this->postJson('/api/v1/auth/login/password', ['email' => 'anna@example.com', 'password' => 'Nope12345']);
        $unknown = $this->postJson('/api/v1/auth/login/password', ['email' => 'nobody@example.com', 'password' => 'Nope12345']);

        $wrong->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
        $this->assertSame($wrong->json(), $unknown->json());
    }

    public function test_без_пароля_в_аккаунте_вход_по_паролю_закрыт(): void
    {
        $this->user(attributes: ['email' => 'anna@example.com']);

        $this->postJson('/api/v1/auth/login/password', ['email' => 'anna@example.com', 'password' => ''])
            ->assertStatus(422);

        $this->postJson('/api/v1/auth/login/password', ['email' => 'anna@example.com', 'password' => 'Anything123'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_неподтверждённая_почта_по_паролю_не_входит(): void
    {
        $user = $this->unverified(['email' => 'new@example.com']);
        $user->setPassword('Borsch2026');

        $this->postJson('/api/v1/auth/login/password', ['email' => 'new@example.com', 'password' => 'Borsch2026'])
            ->assertStatus(422);
    }

    public function test_отключённый_аккаунт_не_входит(): void
    {
        $user = $this->user(attributes: ['email' => 'anna@example.com', 'is_active' => false]);
        $user->setPassword('Borsch2026');

        $this->postJson('/api/v1/auth/login/password', ['email' => 'anna@example.com', 'password' => 'Borsch2026'])
            ->assertForbidden();
    }

    public function test_перебор_пароля_упирается_в_ограничение(): void
    {
        $user = $this->user(attributes: ['email' => 'anna@example.com']);
        $user->setPassword('Borsch2026');

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/auth/login/password', ['email' => 'anna@example.com', 'password' => 'Wrong'.$i.'abcd'])
                ->assertStatus(422);
        }

        // Шестая попытка блокируется, даже если пароль верный.
        $this->postJson('/api/v1/auth/login/password', ['email' => 'anna@example.com', 'password' => 'Borsch2026'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_attempts');
    }

    public function test_ссылка_из_письма_по_прежнему_работает_при_заданном_пароле(): void
    {
        $user = $this->user();
        $user->setPassword('Borsch2026');
        $plain = app(EmailTokenService::class)->issue($user, EmailTokenType::Login);

        $this->postJson('/api/v1/auth/login/confirm', ['token' => $plain])
            ->assertOk()
            ->assertJsonPath('data.has_password', true);
    }
}
