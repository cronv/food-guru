<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Поля для фильтров каталога: у товаров — материал, страна, цвет; у книг — год и страна. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('material', 80)->nullable()->after('category')->index();
            $table->string('country', 80)->nullable()->after('material')->index();
            $table->string('color', 40)->nullable()->after('country')->index();
        });

        Schema::table('books', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->nullable()->after('author_name')->index();
            $table->string('country', 80)->nullable()->after('year')->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['material', 'country', 'color']));
        Schema::table('books', fn (Blueprint $table) => $table->dropColumn(['year', 'country']));
    }
};
