<?php

namespace App\Services;

use App\Enums\EmailTokenType;
use App\Mail\LoginLinkMail;
use App\Mail\VerifyEmailMail;
use App\Models\EmailToken;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EmailTokenService
{
    /**
     * Выпускает новый токен и гасит все предыдущие того же типа.
     * Возвращает открытое значение — оно попадает только в письмо, в базе лежит хеш.
     */
    public function issue(User $user, EmailTokenType $type, ?string $email = null): string
    {
        $email ??= $user->email;

        // Старые ссылки того же типа перестают работать сразу.
        EmailToken::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $plain = Str::random(64);

        EmailToken::create([
            'user_id' => $user->id,
            'token_hash' => $this->hash($plain),
            'type' => $type,
            'email' => $email,
            'expires_at' => now()->add($type->ttl()),
        ]);

        return $plain;
    }

    /** Находит годный токен по открытому значению. */
    public function find(string $plain, EmailTokenType $type): ?EmailToken
    {
        return EmailToken::query()
            ->with('user')
            ->where('token_hash', $this->hash($plain))
            ->where('type', $type)
            ->usable()
            ->first();
    }

    /**
     * Погасить ссылку. Атомарно: один UPDATE с условием «ещё не использована».
     * Два одновременных перехода по одной ссылке — второй получит false.
     */
    public function consume(EmailToken $token): bool
    {
        $done = EmailToken::whereKey($token->id)->whereNull('used_at')->update(['used_at' => now()]) === 1;
        if ($done) {
            $token->used_at = now();
        }

        return $done;
    }

    /** Когда этому пользователю можно отправить следующее письмо. */
    public function nextAllowedAt(User $user, EmailTokenType $type): ?\DateTimeInterface
    {
        $last = EmailToken::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->latest('created_at')
            ->first();

        if (! $last) {
            return null;
        }

        $next = $last->created_at->addSeconds(config('food_guru.tokens.resend_cooldown_seconds'));

        return $next->isFuture() ? $next : null;
    }

    public function sendVerification(User $user, ?string $email = null): void
    {
        $email ??= $user->pending_email ?? $user->email;
        $plain = $this->issue($user, EmailTokenType::Verify, $email);

        Mail::to($email)->queue(new VerifyEmailMail($user, $this->verifyUrl($plain)));
    }

    public function sendLoginLink(User $user): void
    {
        $plain = $this->issue($user, EmailTokenType::Login);

        Mail::to($user->email)->queue(new LoginLinkMail($user, $this->loginUrl($plain)));
    }

    public function verifyUrl(string $plain): string
    {
        return rtrim(config('food_guru.site_url'), '/').'/verify/'.$plain;
    }

    public function loginUrl(string $plain): string
    {
        return rtrim(config('food_guru.site_url'), '/').'/login/'.$plain;
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
