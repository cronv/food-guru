<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Book;
use App\Models\ClubItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Commerce\PaymentService;
use App\Services\Commerce\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Кошелёк, книги, магазин, закрытая академия. Суммы — в копейках. */
class CommerceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:4200');
    }

    private function funded(int $amount): User
    {
        $user = $this->user();
        app(WalletService::class)->credit($user, $amount, WalletTransaction::ADJUSTMENT, 'Тест');

        return $user;
    }

    private function balance(User $user): int
    {
        return app(WalletService::class)->balance($user);
    }

    private function book(array $extra = []): Book
    {
        $book = Book::create($extra + ['slug' => 'kniga', 'title' => 'Книга', 'price' => 49000, 'is_published' => true, 'published_at' => now()]);
        $book->chapters()->create(['position' => 1, 'title' => 'Вступление', 'body' => 'Открыто всем.', 'is_preview' => true]);
        $book->chapters()->create(['position' => 2, 'title' => 'Глава', 'body' => 'Только купившим.']);

        return $book;
    }

    private function product(array $extra = []): Product
    {
        return Product::create($extra + ['slug' => 'nozh', 'title' => 'Нож шефа', 'price' => 350000, 'stock' => 3, 'is_published' => true]);
    }

    private function order(User $user, array $items, string $delivery = 'pickup')
    {
        return $this->actingAs($user)->postJson('/api/v1/shop/orders', [
            'items' => $items,
            'delivery_method' => $delivery,
            'recipient_name' => 'Анна',
            'phone' => '+7 900 123-45-67',
            'city' => 'Москва',
            'address' => 'ул. Пушкина, 1',
        ]);
    }

    /* ---------- кошелёк ---------- */

    public function test_пополнение_тестовой_оплатой_зачисляет_деньги(): void
    {
        $user = $this->user();

        $payment = $this->actingAs($user)
            ->postJson('/api/v1/me/wallet/topups', ['amount' => 150000, 'method' => 'sbp'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data');

        $this->assertStringContainsString("/wallet/pay/{$payment['id']}", $payment['confirmation_url']);
        $this->assertSame(0, $this->balance($user));

        $this->actingAs($user)->postJson("/api/v1/payments/{$payment['id']}/test-complete", ['result' => 'succeeded'])
            ->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.balance', 150000);

        $this->actingAs($user)->getJson('/api/v1/me/wallet')
            ->assertJsonPath('data.balance', 150000)
            ->assertJsonPath('data.transactions.0.type', 'topup');
    }

    public function test_повторное_подтверждение_платежа_не_зачисляет_дважды(): void
    {
        $user = $this->user();
        $payments = app(PaymentService::class);
        $payment = $payments->createTopUp($user, 50000, 'card');

        $payments->succeed($payment);
        $payments->succeed($payment);

        $this->assertSame(50000, $this->balance($user));
        $this->assertSame(1, WalletTransaction::where('payment_id', $payment->id)->count());
    }

    public function test_в_production_тестовая_оплата_выключена_наглухо(): void
    {
        $user = $this->user();
        $id = $this->actingAs($user)->postJson('/api/v1/me/wallet/topups', ['amount' => 50000, 'method' => 'card'])->json('data.id');
        $this->getJson('/api/v1/payments/methods')->assertJsonPath('data.available', true);

        config(['app.env' => 'production']);

        // Ни подтвердить старый тестовый платёж, ни создать новый.
        $this->actingAs($user)->postJson("/api/v1/payments/{$id}/test-complete", ['result' => 'succeeded'])->assertNotFound();
        $this->actingAs($user)->postJson('/api/v1/me/wallet/topups', ['amount' => 50000, 'method' => 'card'])->assertStatus(503);
        // Сам кошелёк при этом открывается.
        $this->actingAs($user)->getJson('/api/v1/me/wallet')->assertOk()->assertJsonPath('data.balance', 0);
        // Форма пополнения на сайте прячется: шлюза нет.
        $this->getJson('/api/v1/payments/methods')
            ->assertJsonPath('data.test_mode', false)
            ->assertJsonPath('data.available', false);
    }

    public function test_отменённый_платёж_не_зачисляется(): void
    {
        $user = $this->user();
        $id = $this->actingAs($user)->postJson('/api/v1/me/wallet/topups', ['amount' => 50000, 'method' => 'card'])->json('data.id');

        $this->actingAs($user)->postJson("/api/v1/payments/{$id}/test-complete", ['result' => 'canceled'])->assertJsonPath('data.status', 'canceled');
        $this->actingAs($user)->postJson("/api/v1/payments/{$id}/test-complete", ['result' => 'succeeded'])->assertStatus(409);

        $this->assertSame(0, $this->balance($user));
    }

    public function test_чужой_платёж_не_подтвердить_и_не_увидеть(): void
    {
        $id = $this->actingAs($this->user())->postJson('/api/v1/me/wallet/topups', ['amount' => 50000, 'method' => 'card'])->json('data.id');

        $stranger = $this->user();
        $this->actingAs($stranger)->getJson("/api/v1/payments/{$id}")->assertNotFound();
        $this->actingAs($stranger)->postJson("/api/v1/payments/{$id}/test-complete", ['result' => 'succeeded'])->assertNotFound();
    }

    public function test_сумма_пополнения_в_пределах(): void
    {
        $user = $this->user();
        $this->actingAs($user)->postJson('/api/v1/me/wallet/topups', ['amount' => 5000, 'method' => 'card'])->assertJsonValidationErrors('amount');
        $this->actingAs($user)->postJson('/api/v1/me/wallet/topups', ['amount' => 10000, 'method' => 'cash'])->assertJsonValidationErrors('method');
    }

    public function test_неподтверждённый_и_гость_кошелёк_не_видят(): void
    {
        $this->getJson('/api/v1/me/wallet')->assertUnauthorized();
        $this->actingAs($this->unverified())->getJson('/api/v1/me/wallet')->assertForbidden();
    }

    /* ---------- книги ---------- */

    public function test_платная_книга_ознакомительная_глава_открыта_остальное_нет(): void
    {
        $this->book();

        $this->getJson('/api/v1/books/kniga/chapters/1')->assertOk()->assertJsonPath('data.body', 'Открыто всем.');
        $this->getJson('/api/v1/books/kniga/chapters/2')->assertForbidden()->assertJsonPath('code', 'locked');
        $this->actingAs($this->user())->getJson('/api/v1/books/kniga/chapters/2')->assertForbidden();
    }

    public function test_покупка_книги_списывает_один_раз_и_открывает_главы(): void
    {
        $this->book();
        $user = $this->funded(100000);

        $this->actingAs($user)->postJson('/api/v1/books/kniga/purchase')->assertCreated()->assertJsonPath('data.owned', true);
        $this->actingAs($user)->postJson('/api/v1/books/kniga/purchase')->assertStatus(409);

        $this->assertSame(51000, $this->balance($user));
        $this->actingAs($user)->getJson('/api/v1/books/kniga/chapters/2')->assertOk();
    }

    public function test_без_денег_книга_не_покупается(): void
    {
        $this->book();
        $user = $this->funded(10000);

        $this->actingAs($user)->postJson('/api/v1/books/kniga/purchase')
            ->assertStatus(402)
            ->assertJsonPath('code', 'insufficient_funds')
            ->assertJsonPath('shortfall', 39000);

        $this->assertSame(10000, $this->balance($user));
        $this->assertDatabaseCount('book_purchases', 0);
    }

    public function test_pdf_только_купленной_книги(): void
    {
        $this->book();
        $buyer = $this->funded(100000);
        $this->actingAs($buyer)->postJson('/api/v1/books/kniga/purchase');

        $this->actingAs($this->user())->get('/api/v1/books/kniga/pdf')->assertForbidden();

        $response = $this->actingAs($buyer)->get('/api/v1/books/kniga/pdf')->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_подписчик_читает_книгу_из_подписки_но_не_скачивает(): void
    {
        $this->book(['in_subscription' => true]);
        $user = $this->user();
        Subscription::create(['user_id' => $user->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'price' => 0]);

        $this->actingAs($user)->getJson('/api/v1/books/kniga/chapters/2')->assertOk();
        $this->actingAs($user)->get('/api/v1/books/kniga/pdf')->assertForbidden();
    }

    public function test_бесплатную_книгу_купить_нельзя_а_читать_можно(): void
    {
        $this->book(['price' => 0]);
        $this->getJson('/api/v1/books/kniga/chapters/2')->assertOk();
        $this->actingAs($this->funded(100000))->postJson('/api/v1/books/kniga/purchase')->assertStatus(422);
    }

    /* ---------- фильтры каталога ---------- */

    public function test_фильтры_магазина_поиск_цена_материал_цвет_сортировка(): void
    {
        $this->product(['slug' => 'a', 'title' => 'Нож шефа', 'price' => 400000, 'material' => 'Сталь', 'country' => 'Германия', 'color' => 'Стальной']);
        $this->product(['slug' => 'b', 'title' => 'Доска из бука', 'price' => 200000, 'material' => 'Дерево', 'country' => 'Россия', 'color' => 'Натуральный']);
        $this->product(['slug' => 'c', 'title' => 'Нож овощной', 'price' => 100000, 'material' => 'Сталь', 'country' => 'Китай', 'color' => 'Чёрный']);
        $this->product(['slug' => 'd', 'title' => 'Скрытый нож', 'price' => 1000, 'is_published' => false]);

        $slugs = fn (string $qs) => array_column($this->getJson('/api/v1/shop/products?'.$qs)->assertOk()->json('data'), 'slug');

        // Регистр кириллицы игнорирует MySQL; тесты идут на SQLite, поэтому тут — точный регистр.
        $this->assertSame(['c', 'a'], $slugs('q=Нож&sort=price_asc'));
        $this->assertSame(['a', 'c'], $slugs('material[]=Сталь&sort=price_desc'));
        $this->assertSame(['b', 'c'], $slugs('country[]=Россия&country[]=Китай&sort=title'));
        $this->assertSame(['b'], $slugs('price_min=150000&price_max=300000'));
        $this->assertSame(['c'], $slugs('color[]=Чёрный'));
        // % в запросе — буква, а не шаблон «всё».
        $this->assertSame([], $slugs('q=%25'));

        $meta = $this->getJson('/api/v1/shop/products')->json('meta.facets');
        $this->assertSame(['Дерево', 'Сталь'], $meta['materials']);
        $this->assertSame(['min' => 100000, 'max' => 400000], $meta['price']);
    }

    public function test_фильтры_книг_статус_год_автор_страна(): void
    {
        $make = fn (string $slug, array $x) => Book::create($x + ['slug' => $slug, 'title' => $slug, 'is_published' => true, 'published_at' => now()]);
        $make('free-old', ['price' => 0, 'year' => 2010, 'author_name' => 'Иванова', 'country' => 'Россия']);
        $make('paid-new', ['price' => 50000, 'year' => 2024, 'author_name' => 'Громов', 'country' => 'Франция']);
        $make('paid-mid', ['price' => 30000, 'year' => 2018, 'author_name' => 'Громов', 'country' => 'Россия']);

        $slugs = fn (string $qs) => array_column($this->getJson('/api/v1/books?'.$qs)->assertOk()->json('data'), 'slug');

        $this->assertSame(['paid-mid', 'paid-new'], $slugs('status=paid&sort=price_asc'));
        $this->assertSame(['free-old'], $slugs('status=free'));
        $this->assertSame(['paid-new', 'paid-mid'], $slugs('year_from=2015&sort=year_desc'));
        $this->assertSame(['paid-mid', 'paid-new'], $slugs('author[]=Громов&sort=year_asc'));
        $this->assertSame(['free-old', 'paid-mid'], $slugs('country[]=Россия&sort=title'));
        $this->assertSame(['paid-new'], $slugs('q=NEW'));

        $facets = $this->getJson('/api/v1/books')->json('meta.facets');
        $this->assertSame(['Громов', 'Иванова'], $facets['authors']);
        $this->assertSame(['min' => 2010, 'max' => 2024], $facets['years']);
    }

    /* ---------- магазин ---------- */

    public function test_заказ_списывает_деньги_по_ценам_из_базы_и_уменьшает_остаток(): void
    {
        $p = $this->product();
        $user = $this->funded(1000000);

        $this->order($user, [['product_id' => $p->id, 'quantity' => 2, 'price' => 1]], 'courier')
            ->assertCreated()
            ->assertJsonPath('data.items_total', 700000)
            ->assertJsonPath('data.delivery_price', 39000)
            ->assertJsonPath('data.total', 739000)
            ->assertJsonPath('data.status', 'paid');

        $this->assertSame(1000000 - 739000, $this->balance($user));
        $this->assertSame(1, $p->fresh()->stock);
    }

    public function test_больше_остатка_не_заказать(): void
    {
        $p = $this->product(['stock' => 1]);
        $user = $this->funded(1000000);

        // Одна и та же позиция дважды — остаток проверяется по сумме.
        $this->order($user, [['product_id' => $p->id, 'quantity' => 1], ['product_id' => $p->id, 'quantity' => 1]])
            ->assertStatus(422);

        $this->assertSame(1000000, $this->balance($user));
        $this->assertSame(1, $p->fresh()->stock);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_без_денег_заказ_не_создаётся_и_остаток_не_трогается(): void
    {
        $p = $this->product();
        $user = $this->funded(100000);

        $this->order($user, [['product_id' => $p->id, 'quantity' => 1]])->assertStatus(402);

        $this->assertSame(3, $p->fresh()->stock);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_курьеру_нужен_адрес(): void
    {
        $p = $this->product();
        $this->actingAs($this->funded(1000000))->postJson('/api/v1/shop/orders', [
            'items' => [['product_id' => $p->id, 'quantity' => 1]],
            'delivery_method' => 'courier',
            'recipient_name' => 'Анна',
            'phone' => '+7 900 123-45-67',
        ])->assertJsonValidationErrors(['city', 'address']);
    }

    public function test_покупатель_отменяет_заказ_деньги_и_товар_возвращаются(): void
    {
        $p = $this->product();
        $user = $this->funded(1000000);
        $number = $this->order($user, [['product_id' => $p->id, 'quantity' => 2]])->json('data.number');

        $this->actingAs($user)->postJson("/api/v1/me/orders/{$number}/cancel")->assertOk()->assertJsonPath('data.status', 'canceled');

        $this->assertSame(1000000, $this->balance($user));
        $this->assertSame(3, $p->fresh()->stock);
        // Повторная отмена не вернёт деньги второй раз.
        $this->actingAs($user)->postJson("/api/v1/me/orders/{$number}/cancel")->assertStatus(422);
        $this->assertSame(1000000, $this->balance($user));
    }

    public function test_заказы_ведёт_руководитель_по_цепочке_статусов(): void
    {
        $p = $this->product();
        $user = $this->funded(1000000);
        $number = $this->order($user, [['product_id' => $p->id, 'quantity' => 1]])->json('data.number');
        $order = Order::where('number', $number)->first();

        $this->actingAs($this->user(UserRole::ContentManager))->getJson('/api/v1/admin/orders')->assertForbidden();

        $lead = $this->user(UserRole::EditorLead);
        $this->actingAs($lead)->patchJson("/api/v1/admin/orders/{$order->id}", ['status' => 'delivered'])->assertStatus(422);
        $this->actingAs($lead)->patchJson("/api/v1/admin/orders/{$order->id}", ['status' => 'assembling'])->assertOk();

        // Собираемый заказ покупатель уже не отменит сам.
        $this->actingAs($user)->postJson("/api/v1/me/orders/{$number}/cancel")->assertStatus(422);

        $this->actingAs($lead)->patchJson("/api/v1/admin/orders/{$order->id}", ['status' => 'canceled'])->assertOk();
        $this->assertSame(1000000, $this->balance($user));
    }

    public function test_чужой_заказ_не_виден(): void
    {
        $p = $this->product();
        $number = $this->order($this->funded(1000000), [['product_id' => $p->id, 'quantity' => 1]])->json('data.number');

        $this->actingAs($this->user())->getJson("/api/v1/me/orders/{$number}")->assertNotFound();
    }

    /* ---------- закрытая академия ---------- */

    private function plan(): SubscriptionPlan
    {
        return SubscriptionPlan::create(['code' => 'month', 'title' => 'Месяц', 'months' => 1, 'price' => 99000]);
    }

    public function test_подписка_открывает_материалы_и_продлевается_встык(): void
    {
        $this->plan();
        ClubItem::create(['slug' => 'sous', 'kind' => 'recipe', 'title' => 'Су-вид', 'body' => 'Секрет', 'data' => ['steps' => ['Шаг']], 'is_published' => true, 'published_at' => now()]);
        $user = $this->funded(300000);

        $this->actingAs($user)->getJson('/api/v1/club/sous')->assertOk()->assertJsonPath('data.unlocked', false)->assertJsonMissingPath('data.body');

        $this->actingAs($user)->postJson('/api/v1/club/subscribe', ['plan' => 'month'])->assertCreated()->assertJsonPath('data.active', true);
        $this->actingAs($user)->getJson('/api/v1/club/sous')->assertJsonPath('data.body', 'Секрет')->assertJsonPath('data.recipe.steps.0', 'Шаг');

        $firstEnd = Subscription::where('user_id', $user->id)->max('ends_at');
        $this->actingAs($user)->postJson('/api/v1/club/subscribe', ['plan' => 'month'])->assertCreated();

        $second = Subscription::where('user_id', $user->id)->orderByDesc('id')->first();
        $this->assertEquals($firstEnd, $second->starts_at->toDateTimeString());
        $this->assertSame(300000 - 2 * 99000, $this->balance($user));
    }

    public function test_после_окончания_подписки_доступ_закрывается(): void
    {
        ClubItem::create(['slug' => 'x', 'kind' => 'material', 'title' => 'X', 'body' => 'Секрет', 'is_published' => true, 'published_at' => now()]);
        $user = $this->user();
        Subscription::create(['user_id' => $user->id, 'starts_at' => now()->subMonths(2), 'ends_at' => now()->subDay(), 'price' => 0]);

        $this->actingAs($user)->getJson('/api/v1/club/x')->assertJsonPath('data.unlocked', false)->assertJsonMissingPath('data.body');
    }

    public function test_видео_только_по_подписанной_ссылке_и_пока_есть_подписка(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('club/v.mp4', 'video-bytes');
        $item = ClubItem::create(['slug' => 'mk', 'kind' => 'masterclass', 'title' => 'МК', 'is_published' => true, 'published_at' => now()]);
        $item->forceFill(['video_disk' => 'local', 'video_path' => 'club/v.mp4'])->save();

        $user = $this->user();
        $sub = Subscription::create(['user_id' => $user->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'price' => 0]);

        $url = $this->actingAs($user)->getJson('/api/v1/club/mk')->json('data.video_url');
        $this->assertNotNull($url);

        $this->get($url)->assertOk();
        // Без подписи — нельзя.
        $this->get("/api/v1/club-video/{$item->id}?user={$user->id}")->assertForbidden();

        // Подписка кончилась — старая ссылка больше не работает.
        $sub->forceFill(['ends_at' => now()->subMinute()])->save();
        $this->get($url)->assertForbidden();
    }

    public function test_контент_менеджер_загружает_видео_на_закрытый_диск(): void
    {
        Storage::fake('local');
        $item = ClubItem::create(['slug' => 'mk', 'kind' => 'masterclass', 'title' => 'МК']);

        $this->actingAs($this->user(UserRole::ContentManager))
            ->post("/api/v1/admin/club/{$item->id}/video", ['file' => UploadedFile::fake()->create('mk.mp4', 100, 'video/mp4')], ['Accept' => 'application/json'])
            ->assertOk();

        Storage::disk('local')->assertExists($item->fresh()->video_path);
    }

    public function test_контент_менеджер_не_публикует_и_не_меняет_цену_опубликованного(): void
    {
        $manager = $this->user(UserRole::ContentManager);
        $p = $this->product(['price' => 350000]);

        $this->actingAs($manager)->patchJson("/api/v1/admin/products/{$p->id}", ['price' => 100])->assertForbidden();
        $this->actingAs($manager)->patchJson("/api/v1/admin/products/{$p->id}", ['lead' => 'Новое описание'])->assertOk();
        $this->assertSame(350000, $p->fresh()->price);

        // Черновик — можно готовить целиком, но опубликовать нельзя.
        $id = $this->actingAs($manager)->postJson('/api/v1/admin/products', ['title' => 'Сито', 'price' => 50000])->assertCreated()->json('data.id');
        $this->actingAs($manager)->patchJson("/api/v1/admin/products/{$id}", ['price' => 60000])->assertOk();
        $this->actingAs($manager)->patchJson("/api/v1/admin/products/{$id}", ['is_published' => true])->assertForbidden();
        $this->actingAs($this->user(UserRole::EditorLead))->patchJson("/api/v1/admin/products/{$id}", ['is_published' => true])->assertOk();

        $book = $this->book();
        $this->actingAs($manager)->patchJson("/api/v1/admin/books/{$book->id}", ['price' => 0])->assertForbidden();
    }

    public function test_гонка_статуса_заказа_не_перезаписывает_отмену(): void
    {
        $p = $this->product();
        $user = $this->funded(1000000);
        $number = $this->order($user, [['product_id' => $p->id, 'quantity' => 1]])->json('data.number');
        $order = Order::where('number', $number)->first();

        // Покупатель уже отменил, а у сотрудника устаревшая страница.
        $this->actingAs($user)->postJson("/api/v1/me/orders/{$number}/cancel")->assertOk();
        $this->actingAs($this->user(UserRole::EditorLead))->patchJson("/api/v1/admin/orders/{$order->id}", ['status' => 'assembling'])->assertStatus(422);

        $this->assertSame('canceled', $order->fresh()->status);
        $this->assertSame(1000000, $this->balance($user));
    }

    /* ---------- корректировка баланса ---------- */

    public function test_корректировать_баланс_может_только_администратор(): void
    {
        $user = $this->user();

        $this->actingAs($this->user(UserRole::EditorLead))->postJson("/api/v1/admin/users/{$user->id}/wallet", ['amount' => 10000, 'reason' => 'Возврат за брак'])->assertForbidden();

        $this->actingAs($this->user(UserRole::Admin))->postJson("/api/v1/admin/users/{$user->id}/wallet", ['amount' => 10000, 'reason' => 'Возврат за брак'])
            ->assertOk()->assertJsonPath('data.balance', 10000);

        // В минус не уйти и через администратора.
        $this->actingAs($this->user(UserRole::Admin))->postJson("/api/v1/admin/users/{$user->id}/wallet", ['amount' => -20000, 'reason' => 'Ошибка зачисления'])->assertStatus(402);
        $this->assertSame(10000, $this->balance($user));
    }

    public function test_оплата_через_платёж_сохраняет_способ(): void
    {
        $payment = app(PaymentService::class)->createTopUp($this->user(), 10000, 'sberpay');
        $this->assertSame('test', $payment->provider);
        $this->assertSame(Payment::PENDING, $payment->status);
    }

    public function test_денежная_история_не_удаляется_вместе_с_пользователем_и_книгой(): void
    {
        $user = $this->funded(100000);
        $book = $this->book();
        $this->actingAs($user)->postJson("/api/v1/books/{$book->slug}/purchase")->assertSuccessful();

        try {
            $user->delete();
            $this->fail('Пользователь с проводками удалился');
        } catch (QueryException) {
        }

        try {
            $book->delete();
            $this->fail('Купленная книга удалилась');
        } catch (QueryException) {
        }

        $this->assertDatabaseHas('wallet_transactions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('book_purchases', ['user_id' => $user->id, 'book_id' => $book->id]);
    }
}
