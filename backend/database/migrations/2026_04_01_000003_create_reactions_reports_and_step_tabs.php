<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            // Счётчики хранятся в рецепте, чтобы карточка не считала их запросом.
            // Источник правды для оценок — recipe_reactions, счётчики пересчитываются из неё.
            $table->unsignedInteger('views_count')->default(0)->after('reel_path');
            $table->unsignedInteger('likes_count')->default(0)->after('views_count');
            $table->unsignedInteger('dislikes_count')->default(0)->after('likes_count');
        });

        Schema::create('recipe_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reaction', 8);
            $table->timestamps();

            // Одна оценка на человека: защита от двойного голоса — индекс, а не проверка запросом.
            $table->unique(['recipe_id', 'user_id']);
        });

        Schema::create('recipe_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 16);
            $table->text('message')->nullable();
            $table->string('status', 16)->default('open')->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['recipe_id', 'status']);
        });

        Schema::table('recipe_steps', function (Blueprint $table) {
            $table->text('tip')->nullable()->after('hint');
            $table->text('substitution')->nullable()->after('tip');
            $table->text('chef_secret')->nullable()->after('substitution');
        });
    }

    public function down(): void
    {
        Schema::table('recipe_steps', fn (Blueprint $table) => $table->dropColumn(['tip', 'substitution', 'chef_secret']));
        Schema::dropIfExists('recipe_reports');
        Schema::dropIfExists('recipe_reactions');
        Schema::table('recipes', fn (Blueprint $table) => $table->dropColumn(['views_count', 'likes_count', 'dislikes_count']));
    }
};
