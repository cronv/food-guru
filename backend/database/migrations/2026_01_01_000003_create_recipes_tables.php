<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('lead')->nullable();
            $table->unsignedSmallInteger('time_minutes')->nullable();
            $table->unsignedSmallInteger('servings')->default(4);
            $table->string('difficulty', 16)->default('easy');
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            // Короткое описание для превью в Telegram и мессенджерах.
            $table->string('og_description')->nullable();
            $table->unsignedSmallInteger('calories')->nullable();
            $table->unsignedSmallInteger('protein')->nullable();
            $table->unsignedSmallInteger('fat')->nullable();
            $table->unsignedSmallInteger('carbs')->nullable();
            $table->boolean('is_vegetarian')->default(false);
            $table->boolean('is_gluten_free')->default(false);
            // Последний комментарий модератора при возврате на доработку.
            $table->text('moderation_note')->nullable();
            $table->timestamps();

            // Выборка публичного каталога.
            $table->index(['status', 'published_at']);
            // Фильтр по категории внутри каталога.
            $table->index(['category_id', 'status']);
            // Списки в админке «мои рецепты» и «очередь модерации».
            $table->index(['author_id', 'status']);
        });

        // Поиск по названию и лиду. MySQL 8 с ngram умеет русский.
        // Полнотекстовый индекс есть только в MySQL. На SQLite, где гоняются
        // тесты, этот запрос уронил бы миграцию целиком.
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            // Кавычки обязательны: LEAD — зарезервированное слово MySQL 8 (оконная функция).
            DB::statement('ALTER TABLE `recipes` ADD FULLTEXT `recipes_search_index` (`title`, `lead`)');
        }

        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Количество на одну порцию: фронт умножает на выбранное число порций.
            $table->decimal('amount', 8, 3)->nullable();
            $table->string('unit', 32)->nullable();
            $table->unsignedSmallInteger('position')->default(0);

            $table->index(['recipe_id', 'position']);
        });

        Schema::create('recipe_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->text('body');

            $table->index(['recipe_id', 'position']);
        });

        Schema::create('recipe_tag', function (Blueprint $table) {
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['recipe_id', 'tag_id']);
            $table->index('tag_id');
        });

        Schema::create('collection_recipe', function (Blueprint $table) {
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);

            $table->primary(['collection_id', 'recipe_id']);
            $table->index('recipe_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_recipe');
        Schema::dropIfExists('recipe_tag');
        Schema::dropIfExists('recipe_steps');
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('recipes');
    }
};
