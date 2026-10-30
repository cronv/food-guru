<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('rating_points')->default(0)->after('is_active');
            $table->string('rating_level', 16)->default('novice')->after('rating_points');
            // Серия подряд идущих дней захода. Пропустил день — серия обнуляется.
            $table->unsignedSmallInteger('streak_days')->default(0)->after('rating_level');
            $table->unsignedSmallInteger('streak_best')->default(0)->after('streak_days');
            $table->date('last_visit_on')->nullable()->after('streak_best');
            $table->unsignedInteger('visits_total')->default(0)->after('last_visit_on');

            // Таблица лидеров.
            $table->index(['rating_points', 'id'], 'users_rating_index');
        });

        /*
         * Журнал начислений. Нужен не ради истории, а ради идемпотентности:
         * dedup_key не даёт начислить дважды за один и тот же повод —
         * повторный заход в тот же день, второй отзыв к одному рецепту,
         * повторная публикация того же рецепта после возврата на доработку.
         */
        Schema::create('rating_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 32);
            $table->smallInteger('points');
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('dedup_key', 160)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('dedup_key');
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rating_events');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_rating_index');
            $table->dropColumn([
                'rating_points',
                'rating_level',
                'streak_days',
                'streak_best',
                'last_visit_on',
                'visits_total',
            ]);
        });
    }
};
