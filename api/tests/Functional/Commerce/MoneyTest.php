<?php

declare(strict_types=1);

namespace App\Tests\Functional\Commerce;

use App\Commerce\WalletService;
use App\Entity\User;
use App\Tests\Functional\ApiTestCase;

/** Кошелёк, платежи, книги, заказы, подписка. Суммы — в копейках. */
final class MoneyTest extends ApiTestCase
{
    /** @return array<string, string> */
    private function csrf(): array
    {
        return $this->xsrf();
    }

    private function funded(int $amount): User
    {
        $user = $this->user();
        if ($amount > 0) {
            static::getContainer()->get(WalletService::class)->credit((int) $user->getId(), $amount, WalletService::ADJUSTMENT, 'Тест');
        }
        $this->login($user);

        return $user;
    }

    private function balance(User $user): int
    {
        return static::getContainer()->get(WalletService::class)->balance((int) $user->getId());
    }

    private function book(string $slug, int $price, bool $inSubscription = false): int
    {
        $this->db()->insert('books', ['slug' => $slug, 'title' => 'Книга '.$slug, 'price' => $price, 'in_subscription' => (int) $inSubscription, 'is_published' => 1, 'published_at' => '2026-01-01 00:00:00']);
        $id = (int) $this->db()->lastInsertId();
        $this->db()->insert('book_chapters', ['book_id' => $id, 'position' => 1, 'title' => 'Вступление', 'body' => 'Открыто всем.', 'is_preview' => 1]);
        $this->db()->insert('book_chapters', ['book_id' => $id, 'position' => 2, 'title' => 'Глава', 'body' => 'Только купившим.', 'is_preview' => 0]);

        return $id;
    }

    private function product(string $slug, int $price, int $stock): int
    {
        $this->db()->insert('products', ['slug' => $slug, 'title' => 'Товар '.$slug, 'price' => $price, 'stock' => $stock, 'is_published' => 1]);

        return (int) $this->db()->lastInsertId();
    }

    /** @return array<string, mixed> */
    private function orderBody(int $productId, int $quantity, string $method = 'pickup'): array
    {
        return ['items' => [['product_id' => $productId, 'quantity' => $quantity]], 'delivery_method' => $method, 'recipient_name' => 'Анна', 'phone' => '+7 900 123-45-67'];
    }

    public function testTopUpViaTestPaymentCreditsOnce(): void
    {
        $user = $this->funded(0);

        $payment = $this->json('POST', '/api/v1/me/wallet/topups', $this->csrf(), ['amount' => 50000, 'method' => 'card'])['data'];
        self::assertResponseStatusCodeSame(201);
        self::assertSame('pending', $payment['status']);
        self::assertStringEndsWith('/wallet/pay/'.$payment['id'], $payment['confirmation_url']);

        $done = $this->json('POST', "/api/v1/payments/{$payment['id']}/test-complete", $this->csrf(), ['result' => 'succeeded'])['data'];
        self::assertSame('succeeded', $done['status']);
        self::assertSame(50000, $done['balance']);

        // Повторное подтверждение (провайдер шлёт уведомления несколько раз) — без второго зачисления.
        $this->json('POST', "/api/v1/payments/{$payment['id']}/test-complete", $this->csrf(), ['result' => 'succeeded']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(50000, $this->balance($user));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM wallet_transactions WHERE payment_id = ?', [$payment['id']]));
    }

    public function testCanceledPaymentIsNotCreditedAndForeignPaymentIsHidden(): void
    {
        $user = $this->funded(0);
        $payment = $this->json('POST', '/api/v1/me/wallet/topups', $this->csrf(), ['amount' => 20000, 'method' => 'sbp'])['data'];

        $this->json('POST', "/api/v1/payments/{$payment['id']}/test-complete", $this->csrf(), ['result' => 'canceled']);
        self::assertSame(0, $this->balance($user));

        $this->funded(0);
        $this->json('GET', "/api/v1/payments/{$payment['id']}");
        self::assertResponseStatusCodeSame(404);
    }

    public function testTopUpAmountBoundsInRubles(): void
    {
        $this->funded(0);

        $body = $this->json('POST', '/api/v1/me/wallet/topups', $this->csrf(), ['amount' => 500, 'method' => 'card']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Минимальное пополнение — 100 ₽.'], $body['errors']['amount']);

        $body = $this->json('POST', '/api/v1/me/wallet/topups', $this->csrf(), ['amount' => 6000000, 'method' => 'bitcoin']);
        self::assertSame(['За один раз можно пополнить не больше чем на 50 000 ₽.'], $body['errors']['amount']);
        self::assertSame(['Выберите способ пополнения.'], $body['errors']['method']);
    }

    public function testBookIsChargedOnceAndOpensChapters(): void
    {
        $this->book('platnaya', 49000);
        $user = $this->funded(100000);

        $card = $this->json('POST', '/api/v1/books/platnaya/purchase', $this->csrf(), [])['data'];
        self::assertResponseStatusCodeSame(201);
        self::assertTrue($card['owned']);
        self::assertSame(51000, $this->balance($user));

        $body = $this->json('POST', '/api/v1/books/platnaya/purchase', $this->csrf(), []);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('already_owned', $body['code']);
        // Повторная покупка откатилась целиком: деньги не списаны второй раз.
        self::assertSame(51000, $this->balance($user));

        self::assertSame('Только купившим.', $this->json('GET', '/api/v1/books/platnaya/chapters/2')['data']['body']);
    }

    public function testNotEnoughMoneyGives402WithShortfallAndNoPurchase(): void
    {
        $this->book('dorogaya', 89000);
        $user = $this->funded(30000);

        $body = $this->json('POST', '/api/v1/books/dorogaya/purchase', $this->csrf(), []);

        self::assertResponseStatusCodeSame(402);
        self::assertSame(['code' => 'insufficient_funds', 'balance' => 30000, 'needed' => 89000, 'shortfall' => 59000], array_intersect_key($body, array_flip(['code', 'balance', 'needed', 'shortfall'])));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM book_purchases WHERE user_id = ?', [$user->getId()]));
        self::assertSame(30000, $this->balance($user));
    }

    public function testFreeBookCannotBeBoughtButCanBeDownloaded(): void
    {
        $this->book('besplatnaya', 0);
        $this->funded(0);

        $body = $this->json('POST', '/api/v1/books/besplatnaya/purchase', $this->csrf(), []);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Эта книга бесплатная — её не нужно покупать.'], $body['errors']['book']);

        $this->client->request('GET', '/api/v1/books/besplatnaya/pdf', server: ['HTTP_ORIGIN' => self::SITE]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());
    }

    public function testSubscriberReadsButCannotDownloadSubscriptionBook(): void
    {
        $this->book('po-podpiske', 89000, inSubscription: true);
        $this->db()->insert('subscription_plans', ['code' => 'month', 'title' => 'Месяц', 'months' => 1, 'price' => 49000, 'is_active' => 1, 'position' => 1]);
        $user = $this->funded(200000);

        self::assertTrue($this->json('POST', '/api/v1/club/subscribe', $this->csrf(), ['plan' => 'month'])['data']['active']);
        self::assertTrue($this->json('GET', '/api/v1/books/po-podpiske')['data']['can_read']);

        $this->client->request('GET', '/api/v1/books/po-podpiske/pdf', server: ['HTTP_ORIGIN' => self::SITE, 'HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(151000, $this->balance($user));
    }

    public function testSecondSubscriptionStartsWhereTheFirstEnds(): void
    {
        $this->db()->insert('subscription_plans', ['code' => 'month', 'title' => 'Месяц', 'months' => 1, 'price' => 49000, 'is_active' => 1, 'position' => 1]);
        $user = $this->funded(100000);

        $this->json('POST', '/api/v1/club/subscribe', $this->csrf(), ['plan' => 'month']);
        $this->json('POST', '/api/v1/club/subscribe', $this->csrf(), ['plan' => 'month']);

        $periods = $this->db()->fetchAllAssociative('SELECT starts_at, ends_at FROM subscriptions WHERE user_id = ? ORDER BY id', [$user->getId()]);
        self::assertCount(2, $periods);
        self::assertSame($periods[0]['ends_at'], $periods[1]['starts_at']);
        self::assertSame(2000, $this->balance($user));

        $this->json('POST', '/api/v1/club/subscribe', $this->csrf(), ['plan' => 'net-takogo']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testOrderUsesDatabasePricesAndDecrementsStock(): void
    {
        $knife = $this->product('nozh', 250000, 5);
        $user = $this->funded(1000000);

        // Цена в запросе не используется — только из базы.
        $order = $this->json('POST', '/api/v1/shop/orders', $this->csrf(), ['items' => [['product_id' => $knife, 'quantity' => 2, 'price' => 1]], 'delivery_method' => 'courier', 'recipient_name' => 'Анна', 'phone' => '+7 900 123-45-67', 'city' => 'Москва', 'address' => 'ул. Ленина, 1'])['data'];

        self::assertResponseStatusCodeSame(201);
        self::assertSame(500000, $order['items_total']);
        self::assertSame(539000, $order['total']);
        self::assertSame('paid', $order['status']);
        self::assertMatchesRegularExpression('/^FG-\d{4}-[A-Z0-9]{4}$/', $order['number']);
        self::assertSame(3, (int) $this->db()->fetchOne('SELECT stock FROM products WHERE id = ?', [$knife]));
        self::assertSame(461000, $this->balance($user));
    }

    public function testNotMoreThanStockAndNothingChangesWhenMoneyIsShort(): void
    {
        $board = $this->product('doska', 300000, 1);

        $user = $this->funded(1000000);
        $body = $this->json('POST', '/api/v1/shop/orders', $this->csrf(), $this->orderBody($board, 2));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['«Товар doska»: в наличии только 1 шт.'], $body['errors']["items.{$board}"]);

        $poor = $this->funded(1000);
        $this->json('POST', '/api/v1/shop/orders', $this->csrf(), $this->orderBody($board, 1));
        self::assertResponseStatusCodeSame(402);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT stock FROM products WHERE id = ?', [$board]));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM orders WHERE user_id IN (?, ?)', [$user->getId(), $poor->getId()]));
    }

    public function testCourierNeedsAddress(): void
    {
        $item = $this->product('venchik', 50000, 10);
        $this->funded(1000000);

        $body = $this->json('POST', '/api/v1/shop/orders', $this->csrf(), $this->orderBody($item, 1, 'courier'));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Укажите город.'], $body['errors']['city']);
        self::assertSame(['Укажите адрес доставки.'], $body['errors']['address']);
    }

    public function testBuyerCancelsAndGetsMoneyAndStockBack(): void
    {
        $item = $this->product('kastryulya', 400000, 3);
        $user = $this->funded(500000);
        $order = $this->json('POST', '/api/v1/shop/orders', $this->csrf(), $this->orderBody($item, 1))['data'];

        $canceled = $this->json('POST', "/api/v1/me/orders/{$order['number']}/cancel", $this->csrf(), [])['data'];

        self::assertSame('canceled', $canceled['status']);
        self::assertSame(500000, $this->balance($user));
        self::assertSame(3, (int) $this->db()->fetchOne('SELECT stock FROM products WHERE id = ?', [$item]));
        self::assertSame('refund', $this->db()->fetchOne('SELECT type FROM wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$user->getId()]));

        // Второй раз отменить нельзя — и денег второй раз не вернут.
        $this->json('POST', "/api/v1/me/orders/{$order['number']}/cancel", $this->csrf(), []);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(500000, $this->balance($user));
    }

    public function testForeignOrderIsHidden(): void
    {
        $item = $this->product('termometr', 10000, 5);
        $this->funded(100000);
        $order = $this->json('POST', '/api/v1/shop/orders', $this->csrf(), $this->orderBody($item, 1))['data'];

        $this->funded(0);
        $this->json('GET', "/api/v1/me/orders/{$order['number']}");
        self::assertResponseStatusCodeSame(404);
    }

    public function testWalletNeedsVerifiedAccount(): void
    {
        $this->json('GET', '/api/v1/me/wallet');
        self::assertResponseStatusCodeSame(401);

        $user = $this->user();
        $this->db()->update('users', ['email_verified_at' => null], ['id' => $user->getId()]);
        $this->login($user);
        $this->json('GET', '/api/v1/me/wallet');
        self::assertResponseStatusCodeSame(403);
    }
}
