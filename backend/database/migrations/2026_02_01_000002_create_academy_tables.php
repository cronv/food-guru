<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academy_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('lead')->nullable();
            $table->longText('description')->nullable();
            // Чему научимся: короткие пункты, по одному в строке.
            $table->text('takeaways')->nullable();

            $table->string('format', 16)->default('webinar');
            $table->string('level', 16)->default('basic');
            $table->string('status', 16)->default('draft');

            $table->string('host_name')->nullable();
            $table->string('host_title')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            /*
             * Ссылка на эфир и ссылка на запись отдаются только записавшимся,
             * поэтому в карточку списка они не попадают никогда.
             */
            $table->string('stream_url')->nullable();
            $table->string('record_url')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('registrations_count')->default(0);
            $table->string('moderation_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at']);
            $table->index(['status', 'published_at']);
            // Полнотекстовый индекс есть только в MySQL. На SQLite, где гоняются
            // тесты, его создание уронило бы миграцию целиком.
            if (in_array($this->driver(), ['mysql', 'mariadb'], true)) {
                $table->fullText(['title', 'lead']);
            }
        });

        Schema::create('academy_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academy_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Письмо-напоминание перед стартом: чтобы не отправить дважды.
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('watched_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['academy_session_id', 'user_id'], 'academy_registration_unique');
            $table->index(['user_id', 'created_at']);
        });
    }

    /** Драйвер подключения: от него зависит, доступен ли полнотекстовый индекс. */
    private function driver(): string
    {
        return Schema::getConnection()->getDriverName();
    }

    public function down(): void
    {
        Schema::dropIfExists('academy_registrations');
        Schema::dropIfExists('academy_sessions');
    }
};
