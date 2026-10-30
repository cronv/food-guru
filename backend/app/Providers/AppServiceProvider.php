<?php

namespace App\Providers;

use App\Models\AcademySession;
use App\Models\Contest;
use App\Models\JournalIssue;
use App\Models\Recipe;
use App\Models\User;
use App\Policies\AcademyPolicy;
use App\Policies\ContestPolicy;
use App\Policies\JournalPolicy;
use App\Policies\RecipePolicy;
use App\Policies\UserPolicy;
use App\Services\Commerce\PaymentGateway;
use App\Services\Commerce\TestGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Платёжная система по config: сейчас только тестовая. Настоящую добавить сюда.
        $this->app->bind(PaymentGateway::class, function () {
            $provider = config('food_guru.payments.provider');

            // Тестовая оплата зачисляет деньги по кнопке «Оплатить» — в проде это
            // бесплатный баланс для любого. Поэтому там она выключена наглухо.
            if ($provider === 'test' && TestGateway::forbidden()) {
                throw new \RuntimeException('Тестовая оплата выключена в production. Задайте PAYMENT_PROVIDER.');
            }

            return match ($provider) {
                'test' => new TestGateway,
                default => throw new \RuntimeException('Неизвестный PAYMENT_PROVIDER: '.$provider),
            };
        });
    }

    public function boot(): void
    {
        Gate::policy(Recipe::class, RecipePolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Contest::class, ContestPolicy::class);
        Gate::policy(AcademySession::class, AcademyPolicy::class);
        Gate::policy(JournalIssue::class, JournalPolicy::class);

        /*
         * Письма со ссылками. Раньше было 5 в час на IP — на всех: за одним IP сидит
         * весь офис или все абоненты провайдера за NAT, и один любопытный оставлял
         * без входа остальных. Теперь 5 в час на конкретный адрес почты (с этого IP)
         * и общий потолок 30 в час на IP — от рассылки по чужим адресам.
         * Плюс минута между письмами на один адрес — в EmailTokenService.
         */
        RateLimiter::for('auth-mail', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perHour(5)->by('mail:'.sha1($email).'|'.$request->ip()),
                Limit::perHour(30)->by('mail-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('api', function (Request $request) {
            // Запросы серверного рендера: за одним IP SSR-сервера стоят все посетители,
            // поэтому лимит — на посетителя, чей адрес сервер передал. Потолок выше:
            // одна страница — это несколько запросов к API.
            if (($visitor = self::ssrVisitorIp($request)) !== null) {
                return Limit::perMinute(300)->by('ssr:'.$visitor);
            }

            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }

    /** Адрес посетителя из запроса SSR-сервера — только если ключ совпал. */
    public static function ssrVisitorIp(Request $request): ?string
    {
        $key = (string) config('food_guru.ssr_key');
        $given = (string) $request->header('X-SSR-Key');

        if ($key === '' || $given === '' || ! hash_equals($key, $given)) {
            return null;
        }

        return filter_var($request->header('X-Client-IP'), FILTER_VALIDATE_IP) ?: 'unknown';
    }
}
