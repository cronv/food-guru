<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Деньги, книги, магазин и закрытая академия.
 *
 * Все суммы — целые копейки (bigint/unsigned int): 990 ₽ = 99000. Никаких
 * float и decimal-арифметики в PHP — так не теряются копейки при сложении.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ---------- кошелёк ---------- */

        // Баланс — кэш суммы проводок. Источник правды — wallet_transactions.
        Schema::create('wallets', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('balance')->default(0);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('method', 16);
            $table->string('provider', 24);
            $table->string('provider_payment_id')->nullable()->index();
            $table->string('status', 16)->default('pending')->index();
            $table->string('confirmation_url', 2048)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Со знаком: пополнение и возврат — плюс, покупка — минус.
            $table->bigInteger('amount');
            $table->unsignedBigInteger('balance_after');
            $table->string('type', 16);
            $table->string('description', 255);
            $table->string('subject_type', 32)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            // Один платёж — одно зачисление, даже если подтверждение пришло дважды.
            $table->uuid('payment_id')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'id']);
        });

        /* ---------- книги ---------- */

        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 160)->unique();
            $table->string('title', 200);
            $table->string('author_name', 160)->nullable();
            $table->string('lead', 400)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            // 0 — бесплатная.
            $table->unsignedInteger('price')->default(0);
            // Платная книга, которую подписчик академии читает без покупки.
            $table->boolean('in_subscription')->default(false);
            $table->boolean('is_published')->default(false)->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('book_chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('title', 200);
            $table->longText('body');
            // Ознакомительная глава: её читают все, даже у платной книги.
            $table->boolean('is_preview')->default(false);
            $table->timestamps();

            $table->unique(['book_id', 'position']);
        });

        Schema::create('book_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('price');
            $table->foreignId('wallet_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'book_id']);
        });

        /* ---------- магазин ---------- */

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 160)->unique();
            $table->string('title', 200);
            $table->string('category', 80)->nullable()->index();
            $table->string('lead', 400)->nullable();
            $table->text('description')->nullable();
            $table->string('sku', 64)->nullable();
            $table->unsignedInteger('price');
            $table->unsignedInteger('old_price')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->boolean('is_published')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->index();
            $table->unsignedInteger('items_total');
            $table->string('delivery_method', 16);
            $table->unsignedInteger('delivery_price');
            $table->unsignedInteger('total');
            $table->string('recipient_name', 160);
            $table->string('phone', 32);
            $table->string('city', 120)->nullable();
            $table->string('address', 400)->nullable();
            $table->string('comment', 1000)->nullable();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
        });

        // Название и цена копируются в заказ: правка товара не меняет прошлые заказы.
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->unsignedInteger('price');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedInteger('total');
        });

        /* ---------- закрытая академия ---------- */

        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('title', 120);
            $table->unsignedSmallInteger('months');
            $table->unsignedInteger('price');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedInteger('price');
            $table->foreignId('wallet_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'ends_at']);
        });

        Schema::create('club_items', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 160)->unique();
            // video | masterclass | recipe | material
            $table->string('kind', 16)->index();
            $table->string('title', 200);
            $table->string('lead', 400)->nullable();
            $table->longText('body')->nullable();
            $table->string('chef_name', 160)->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            // Видео лежит на закрытом диске и отдаётся только по подписанной ссылке.
            $table->string('video_disk', 16)->nullable();
            $table->string('video_path')->nullable();
            // Для ресторанных рецептов: ингредиенты и шаги.
            $table->json('data')->nullable();
            $table->boolean('is_published')->default(false)->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'club_items', 'subscriptions', 'subscription_plans', 'order_items', 'orders', 'products',
            'book_purchases', 'book_chapters', 'books', 'wallet_transactions', 'payments', 'wallets',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
