<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Рецепты сайта в рецептосохранялке. Отдельно от избранного (favorites):
 * избранное — «нравится», рецептосохранялка — личная книга «буду готовить»,
 * и рейтинг на главной считается именно по ней.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cookbook_recipes', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['user_id', 'recipe_id']);
            $table->index(['recipe_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cookbook_recipes');
    }
};
