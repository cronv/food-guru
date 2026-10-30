<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Денежная история не должна исчезать вместе с пользователем или книгой:
 * каскад на кошельках, проводках, платежах, заказах, покупках и подписках
 * меняем на RESTRICT — удалить такого пользователя можно только осознанно.
 * Заодно индексы под очередь модерации отзывов, счётчики админки и чистку токенов.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> таблица => колонки, ссылки которых перестают каскадиться */
    private array $keys = [
        'wallets' => ['user_id'],
        'wallet_transactions' => ['user_id'],
        'payments' => ['user_id'],
        'orders' => ['user_id'],
        'book_purchases' => ['user_id', 'book_id'],
        'subscriptions' => ['user_id'],
    ];

    public function up(): void
    {
        $this->rewireKeys('restrict');

        Schema::table('reviews', fn (Blueprint $table) => $table->index(['status', 'created_at']));
        Schema::table('contest_entries', fn (Blueprint $table) => $table->index(['status', 'created_at']));
        Schema::table('users', fn (Blueprint $table) => $table->index('created_at'));
        Schema::table('email_tokens', fn (Blueprint $table) => $table->index('expires_at'));
    }

    public function down(): void
    {
        Schema::table('reviews', fn (Blueprint $table) => $table->dropIndex(['status', 'created_at']));
        Schema::table('contest_entries', fn (Blueprint $table) => $table->dropIndex(['status', 'created_at']));
        Schema::table('users', fn (Blueprint $table) => $table->dropIndex(['created_at']));
        Schema::table('email_tokens', fn (Blueprint $table) => $table->dropIndex(['expires_at']));

        $this->rewireKeys('cascade');
    }

    private function rewireKeys(string $onDelete): void
    {
        foreach ($this->keys as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns, $onDelete) {
                foreach ($columns as $column) {
                    $table->dropForeign([$column]);
                    $table->foreign($column)
                        ->references('id')
                        ->on($column === 'book_id' ? 'books' : 'users')
                        ->onDelete($onDelete);
                }
            });
        }
    }
};
