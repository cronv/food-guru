<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('lead')->nullable();
            $table->longText('description')->nullable();
            $table->longText('rules')->nullable();
            $table->text('prize')->nullable();

            // Что присылают: фото, видео или рецепт целиком.
            $table->string('kind', 16)->default('photo');
            $table->string('status', 16)->default('draft');

            /*
             * Фазы конкурса считаются из этих дат, отдельного поля «фаза» нет:
             * иначе фаза и даты рано или поздно разойдутся.
             */
            $table->timestamp('submissions_open_at')->nullable();
            $table->timestamp('submissions_close_at')->nullable();
            $table->timestamp('voting_open_at')->nullable();
            $table->timestamp('voting_close_at')->nullable();
            $table->timestamp('results_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->unsignedTinyInteger('max_entries_per_user')->default(1);
            $table->unsignedInteger('entries_count')->default(0);
            $table->unsignedInteger('votes_count')->default(0);
            $table->string('moderation_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
            $table->index(['status', 'voting_close_at']);
            // Полнотекстовый индекс есть только в MySQL. На SQLite, где гоняются
            // тесты, его создание уронило бы миграцию целиком.
            if (in_array($this->driver(), ['mysql', 'mariadb'], true)) {
                $table->fullText(['title', 'lead']);
            }
        });

        Schema::create('contest_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            // Работы проходят модерацию: в галерею попадают только approved.
            $table->string('status', 16)->default('pending');
            $table->string('reject_reason')->nullable();
            $table->unsignedInteger('votes_count')->default(0);
            // 1, 2, 3 — призовые места, null — без места.
            $table->unsignedTinyInteger('place')->nullable();
            $table->timestamps();

            $table->index(['contest_id', 'status', 'votes_count']);
            $table->index(['contest_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('contest_entry_media', function (Blueprint $table) {
            $table->foreignId('contest_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);

            $table->primary(['contest_entry_id', 'media_id'], 'contest_entry_media_primary');
            $table->index('media_id');
        });

        Schema::create('contest_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contest_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            // Один голос на конкурс от человека — защита на уровне базы, не только в коде.
            $table->unique(['contest_id', 'user_id']);
            $table->index('contest_entry_id');
        });
    }

    /** Драйвер подключения: от него зависит, доступен ли полнотекстовый индекс. */
    private function driver(): string
    {
        return Schema::getConnection()->getDriverName();
    }

    public function down(): void
    {
        Schema::dropIfExists('contest_votes');
        Schema::dropIfExists('contest_entry_media');
        Schema::dropIfExists('contest_entries');
        Schema::dropIfExists('contests');
    }
};
