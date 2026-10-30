<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('body');
            // Отзыв виден не сразу: сначала модерация, как и рецепты.
            $table->string('status', 16)->default('published');
            $table->timestamps();

            // Один отзыв на рецепт от человека.
            $table->unique(['recipe_id', 'user_id']);
            $table->index(['recipe_id', 'status', 'created_at']);
        });

        Schema::create('review_media', function (Blueprint $table) {
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);

            $table->primary(['review_id', 'media_id']);
            $table->index('media_id');
        });

        // Галерея рецепта: кадры сверх обложки.
        Schema::create('recipe_media', function (Blueprint $table) {
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained()->cascadeOnDelete();
            $table->string('caption')->nullable();
            $table->unsignedSmallInteger('position')->default(0);

            $table->primary(['recipe_id', 'media_id']);
            $table->index('media_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_media');
        Schema::dropIfExists('review_media');
        Schema::dropIfExists('reviews');
    }
};
