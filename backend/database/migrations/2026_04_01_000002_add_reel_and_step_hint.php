<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            // Вертикальное видео рецепта. Путь на публичном диске, не в медиатеке:
            // медиатека хранит картинки, а видео отдаётся плеером отдельным блоком.
            $table->string('reel_path')->nullable()->after('cover_media_id');
        });

        Schema::table('recipe_steps', function (Blueprint $table) {
            // Короткая подсказка к шагу: температура, время, «не больше двух минут».
            $table->string('hint', 160)->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('recipe_steps', fn (Blueprint $table) => $table->dropColumn('hint'));
        Schema::table('recipes', fn (Blueprint $table) => $table->dropColumn('reel_path'));
    }
};
