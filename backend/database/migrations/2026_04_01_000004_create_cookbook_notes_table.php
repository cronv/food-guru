<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Рецептосохранялка — личная кулинарная книга. В ней два вида записей:
 * рецепты сайта (это избранное, таблица favorites) и свои заметки — рецепт
 * с другого сайта по ссылке или записанный от руки. Заметки — эта таблица.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cookbook_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('url', 2048)->nullable();
            $table->text('body')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cookbook_notes');
    }
};
