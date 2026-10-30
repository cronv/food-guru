<?php

use App\Http\Controllers\Api\AcademyController;
use App\Http\Controllers\Api\Admin\AdminAcademyController;
use App\Http\Controllers\Api\Admin\AdminBookController;
use App\Http\Controllers\Api\Admin\AdminClubController;
use App\Http\Controllers\Api\Admin\AdminContestController;
use App\Http\Controllers\Api\Admin\AdminJournalController;
use App\Http\Controllers\Api\Admin\AdminRecipeController;
use App\Http\Controllers\Api\Admin\AdminReportController;
use App\Http\Controllers\Api\Admin\AdminShopController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AdminWalletController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\MediaController;
use App\Http\Controllers\Api\Auth\LoginLinkController;
use App\Http\Controllers\Api\Auth\PasswordLoginController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\ResendController;
use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\Auth\VerifyController;
use App\Http\Controllers\Api\BookController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ClubController;
use App\Http\Controllers\Api\ContestController;
use App\Http\Controllers\Api\CookbookController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\JournalController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MyRecipeController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\RatingController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\RecipeEngagementController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SeoController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

/*
 * Префикс /api/v1 задаётся в bootstrap/app.php.
 */

/* ---------- аутентификация ---------- */
Route::prefix('auth')->group(function () {
    // Почтовые маршруты: 5 запросов в час с IP, плюс минутный кулдаун на адрес.
    Route::middleware('throttle:auth-mail')->group(function () {
        Route::post('register', RegisterController::class);
        Route::post('resend', ResendController::class);
        Route::post('login', [LoginLinkController::class, 'request']);
    });

    // Свой счётчик попыток внутри контроллера: по связке «адрес + IP».
    Route::post('login/password', PasswordLoginController::class);
    Route::post('verify', VerifyController::class);
    Route::post('login/confirm', [LoginLinkController::class, 'confirm']);
    Route::post('logout', [SessionController::class, 'destroy'])->middleware('auth:sanctum');
});

/* ---------- публичная часть ---------- */
Route::get('home', [CatalogController::class, 'home']);
Route::get('categories', [CatalogController::class, 'categories']);
Route::get('categories/{slug}', [CatalogController::class, 'category']);
Route::get('tags', [CatalogController::class, 'tags']);
Route::get('collections', [CatalogController::class, 'collections']);
Route::get('collections/{slug}', [CatalogController::class, 'collection']);

Route::get('reels', [CatalogController::class, 'reels']);
Route::get('recipes', [RecipeController::class, 'index']);
Route::get('recipes/{slug}', [RecipeController::class, 'show']);
Route::get('recipes/{slug}/related', [RecipeController::class, 'related']);
Route::get('recipes/{slug}/reviews', [ReviewController::class, 'index']);
// Просмотр отмечает браузер после загрузки; гостям тоже, по одному в сутки.
Route::post('recipes/{slug}/view', [RecipeEngagementController::class, 'view']);

/* ---------- конкурсы ---------- */
Route::get('contests', [ContestController::class, 'index']);
Route::get('contests/{slug}', [ContestController::class, 'show']);
Route::get('contests/{slug}/entries', [ContestController::class, 'entries']);

/* ---------- кулинарная академия ---------- */
Route::get('academy', [AcademyController::class, 'index']);
Route::get('academy/{slug}', [AcademyController::class, 'show']);

/* ---------- журнал ---------- */
// periods объявлен раньше {slug}, иначе «periods» уедет в него как адрес выпуска.
Route::get('journal', [JournalController::class, 'index']);
Route::get('journal/periods', [JournalController::class, 'periods']);
Route::get('journal/{slug}', [JournalController::class, 'show']);
Route::get('journal/{slug}/download', [JournalController::class, 'download']);

/* ---------- рейтинг ---------- */
Route::get('rating', [RatingController::class, 'leaderboard']);

/* ---------- книги, магазин, закрытая академия: витрины видны всем ---------- */
Route::get('books', [BookController::class, 'index']);
Route::get('books/{slug}', [BookController::class, 'show']);
// Ознакомительные главы читают и гости; закрытые проверяются внутри.
Route::get('books/{slug}/chapters/{position}', [BookController::class, 'chapter'])->whereNumber('position');

Route::get('shop/products', [ShopController::class, 'products']);
Route::get('shop/products/{slug}', [ShopController::class, 'product']);
Route::get('shop/delivery', [ShopController::class, 'delivery']);

Route::get('club', [ClubController::class, 'index']);
Route::get('club/{slug}', [ClubController::class, 'show']);
// Видео — по временной подписанной ссылке: тег <video> не шлёт cookie на другой домен.
Route::get('club-video/{item}', [ClubController::class, 'video'])->middleware('signed')->name('club.video');

Route::get('payments/methods', [WalletController::class, 'methods']);

// Список публичных адресов для sitemap.xml (XML собирает SSR-сервер сайта).
Route::get('seo/sitemap', [SeoController::class, 'sitemap']);

/* ---------- личный кабинет ---------- */
Route::middleware(['auth:sanctum', 'track.visit'])->group(function () {
    Route::get('me', [MeController::class, 'show']);
    Route::patch('me', [MeController::class, 'update']);
    Route::put('me/password', [PasswordController::class, 'update']);
    Route::get('me/rating', [RatingController::class, 'me']);

    // Эти действия требуют подтверждённого адреса.
    Route::middleware('email.verified')->group(function () {
        Route::get('me/favorites', [FavoriteController::class, 'index']);

        // Рецептосохранялка: рецепты сайта и свои заметки. С избранным не связана.
        Route::get('me/cookbook/recipes', [CookbookController::class, 'recipes']);
        Route::put('recipes/{recipe:id}/cookbook', [CookbookController::class, 'saveRecipe']);
        Route::delete('recipes/{recipe:id}/cookbook', [CookbookController::class, 'forgetRecipe']);
        Route::get('me/cookbook/notes', [CookbookController::class, 'index']);
        Route::post('me/cookbook/notes', [CookbookController::class, 'store']);
        Route::patch('me/cookbook/notes/{note}', [CookbookController::class, 'update']);
        Route::delete('me/cookbook/notes/{note}', [CookbookController::class, 'destroy']);
        Route::put('recipes/{recipe:id}/favorite', [FavoriteController::class, 'store']);
        Route::delete('recipes/{recipe:id}/favorite', [FavoriteController::class, 'destroy']);

        Route::post('recipes/{slug}/reviews', [ReviewController::class, 'store']);

        // Оценка и жалоба — только с подтверждённой почтой, иначе их легко накрутить.
        Route::put('recipes/{recipe:id}/reaction', [RecipeEngagementController::class, 'react']);
        Route::delete('recipes/{recipe:id}/reaction', [RecipeEngagementController::class, 'unreact']);
        Route::post('recipes/{slug}/reports', [RecipeEngagementController::class, 'report']);
        Route::delete('reviews/{review}', [ReviewController::class, 'destroy']);

        // Конкурсы: прислать работу и отдать голос.
        Route::get('me/contest-entries', [ContestController::class, 'myEntries']);
        Route::post('contests/{slug}/entries', [ContestController::class, 'storeEntry']);
        Route::post('contests/{slug}/entries/{entry}/vote', [ContestController::class, 'vote']);

        // Академия: запись на эфир открывает ссылки на эфир и запись.
        Route::get('me/academy', [AcademyController::class, 'myRegistrations']);
        Route::put('academy/{slug}/registration', [AcademyController::class, 'register']);
        Route::delete('academy/{slug}/registration', [AcademyController::class, 'unregister']);

        // Рецепты читателей: черновик → модерация → публикация редакцией.
        Route::get('me/recipes', [MyRecipeController::class, 'index']);
        Route::post('me/recipes', [MyRecipeController::class, 'store'])->middleware('throttle:30,60');
        Route::post('me/recipes/media', [MyRecipeController::class, 'upload'])->middleware('throttle:60,60');
        Route::get('me/recipes/{recipe:id}', [MyRecipeController::class, 'show']);
        Route::patch('me/recipes/{recipe:id}', [MyRecipeController::class, 'update']);
        Route::delete('me/recipes/{recipe:id}', [MyRecipeController::class, 'destroy']);
        Route::post('me/recipes/{recipe:id}/submit', [MyRecipeController::class, 'submit']);
        Route::post('me/recipes/{recipe:id}/withdraw', [MyRecipeController::class, 'withdraw']);

        // Кошелёк: пополнение и история. Покупки ниже платятся только с баланса.
        Route::get('me/wallet', [WalletController::class, 'show']);
        Route::post('me/wallet/topups', [WalletController::class, 'topUp'])->middleware('throttle:20,1');
        Route::get('payments/{payment}', [WalletController::class, 'payment']);
        Route::post('payments/{payment}/test-complete', [WalletController::class, 'testComplete']);

        Route::post('books/{slug}/purchase', [BookController::class, 'purchase']);
        Route::get('books/{slug}/pdf', [BookController::class, 'pdf']);

        Route::post('shop/orders', [ShopController::class, 'placeOrder'])->middleware('throttle:20,1');
        Route::get('me/orders', [ShopController::class, 'myOrders']);
        Route::get('me/orders/{number}', [ShopController::class, 'myOrder']);
        Route::post('me/orders/{number}/cancel', [ShopController::class, 'cancel']);

        Route::get('me/subscription', [ClubController::class, 'mySubscription']);
        Route::post('club/subscribe', [ClubController::class, 'subscribe']);
    });
});

/* ---------- админка ---------- */
Route::prefix('admin')
    ->middleware(['auth:sanctum', 'email.verified', 'role:content_manager'])
    ->group(function () {
        Route::get('stats', [DashboardController::class, 'stats']);

        Route::get('recipes', [AdminRecipeController::class, 'index']);
        Route::post('recipes', [AdminRecipeController::class, 'store']);
        Route::get('recipes/{recipe:id}', [AdminRecipeController::class, 'show']);
        Route::patch('recipes/{recipe:id}', [AdminRecipeController::class, 'update']);
        Route::post('recipes/{recipe:id}/reel', [AdminRecipeController::class, 'uploadReel']);
        Route::delete('recipes/{recipe:id}/reel', [AdminRecipeController::class, 'deleteReel']);
        Route::post('recipes/{recipe:id}/submit', [AdminRecipeController::class, 'submit']);
        Route::post('recipes/{recipe:id}/publish', [AdminRecipeController::class, 'publish']);
        Route::post('recipes/{recipe:id}/reject', [AdminRecipeController::class, 'reject']);
        Route::post('recipes/{recipe:id}/archive', [AdminRecipeController::class, 'archive']);

        /* конкурсы */
        Route::get('contests', [AdminContestController::class, 'index']);
        Route::post('contests', [AdminContestController::class, 'store']);
        Route::get('contests/{contest:id}', [AdminContestController::class, 'show']);
        Route::patch('contests/{contest:id}', [AdminContestController::class, 'update']);
        Route::post('contests/{contest:id}/status/{action}', [AdminContestController::class, 'move'])
            ->whereIn('action', ['publish', 'archive', 'draft', 'moderation']);
        Route::get('contests/{contest:id}/entries', [AdminContestController::class, 'entries']);
        Route::post('contests/{contest:id}/entries/{entry}/moderate', [AdminContestController::class, 'moderateEntry']);
        Route::post('contests/{contest:id}/winners', [AdminContestController::class, 'setWinners']);

        /* журнал */
        Route::get('journal', [AdminJournalController::class, 'index']);
        Route::post('journal', [AdminJournalController::class, 'store']);
        Route::get('journal/{issue:id}', [AdminJournalController::class, 'show']);
        Route::patch('journal/{issue:id}', [AdminJournalController::class, 'update']);
        Route::post('journal/{issue:id}/file', [AdminJournalController::class, 'uploadFile']);
        Route::delete('journal/{issue:id}/file', [AdminJournalController::class, 'deleteFile']);
        Route::post('journal/{issue:id}/cover', [AdminJournalController::class, 'renderCover']);
        Route::post('journal/{issue:id}/status/{action}', [AdminJournalController::class, 'move'])
            ->whereIn('action', ['publish', 'archive', 'draft', 'moderation']);

        /* академия */
        Route::get('academy', [AdminAcademyController::class, 'index']);
        Route::post('academy', [AdminAcademyController::class, 'store']);
        Route::get('academy/{session:id}', [AdminAcademyController::class, 'show']);
        Route::patch('academy/{session:id}', [AdminAcademyController::class, 'update']);
        Route::post('academy/{session:id}/status/{action}', [AdminAcademyController::class, 'move'])
            ->whereIn('action', ['publish', 'archive', 'draft', 'moderation']);
        Route::get('academy/{session:id}/registrations', [AdminAcademyController::class, 'registrations']);

        /* книги */
        Route::get('books', [AdminBookController::class, 'index']);
        Route::post('books', [AdminBookController::class, 'store']);
        Route::get('books/{book}', [AdminBookController::class, 'show']);
        Route::patch('books/{book}', [AdminBookController::class, 'update']);

        /* магазин: товары */
        Route::get('products', [AdminShopController::class, 'products']);
        Route::post('products', [AdminShopController::class, 'storeProduct']);
        Route::get('products/{product}', [AdminShopController::class, 'product']);
        Route::patch('products/{product}', [AdminShopController::class, 'updateProduct']);

        /* закрытая академия */
        Route::get('club', [AdminClubController::class, 'index']);
        Route::post('club', [AdminClubController::class, 'store']);
        Route::get('club/{item}', [AdminClubController::class, 'show']);
        Route::patch('club/{item}', [AdminClubController::class, 'update']);
        Route::post('club/{item}/video', [AdminClubController::class, 'uploadVideo']);

        Route::get('media', [MediaController::class, 'index']);
        Route::post('media', [MediaController::class, 'store']);
        Route::delete('media/{media}', [MediaController::class, 'destroy']);

        // Дальше — только руководитель редакции и выше (проверка внутри policies).
        Route::get('users', [AdminUserController::class, 'index']);
        Route::patch('users/{user}', [AdminUserController::class, 'update']);
        Route::post('users/{user}/resend-verification', [AdminUserController::class, 'resendVerification']);

        Route::get('activity', [DashboardController::class, 'activity']);

        /* жалобы читателей */
        Route::middleware('role:editor_lead')->group(function () {
            Route::get('reports', [AdminReportController::class, 'index']);
            Route::post('reports/{report}/resolve', [AdminReportController::class, 'resolve']);

            // Заказы — это деньги покупателей: менеджеру контента их не меняем.
            Route::get('orders', [AdminShopController::class, 'orders']);
            Route::patch('orders/{order}', [AdminShopController::class, 'updateOrder']);
        });

        Route::middleware('role:admin')->group(function () {
            Route::get('users/{user}/wallet', [AdminWalletController::class, 'show']);
            Route::post('users/{user}/wallet', [AdminWalletController::class, 'adjust']);
        });
    });
