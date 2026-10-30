<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            // Обложка — обычная картинка из медиатеки. Если её не загрузили,
            // сервер отрисует первую страницу PDF и положит сюда результат.
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();

            $table->string('slug')->unique();
            $table->string('title');
            $table->text('lead')->nullable();
            $table->longText('description')->nullable();

            // Гайд недели или гайд месяца.
            $table->string('period', 8)->default('month');
            // Для недели — понедельник этой недели, для месяца — первое число.
            $table->date('issue_date');

            /*
             * Год и месяц продублированы колонками намеренно.
             * Фильтр в разделе — именно по ним, а YEAR(issue_date) в условии
             * отключает индекс и превращает фильтр в полный перебор таблицы.
             */
            $table->unsignedSmallInteger('issue_year');
            $table->unsignedTinyInteger('issue_month');

            /*
             * Сам PDF лежит на закрытом диске и отдаётся только через маршрут
             * скачивания: иначе ссылку можно было бы разослать мимо счётчика.
             * Поэтому путь к файлу хранится здесь, а не в медиатеке.
             */
            $table->string('file_path')->nullable();
            $table->string('file_disk', 32)->default('local');
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedSmallInteger('pages')->nullable();

            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('downloads_count')->default(0);
            $table->string('moderation_note')->nullable();
            $table->timestamps();

            // Основной запрос раздела: опубликованные, с фильтром по году и месяцу.
            $table->index(['status', 'issue_year', 'issue_month']);
            $table->index(['status', 'issue_date']);
            // Полнотекстовый индекс есть только в MySQL. На SQLite, где гоняются
            // тесты, его создание уронило бы миграцию целиком.
            if (in_array($this->driver(), ['mysql', 'mariadb'], true)) {
                $table->fullText(['title', 'lead']);
            }
        });
    }

    /** Драйвер подключения: от него зависит, доступен ли полнотекстовый индекс. */
    private function driver(): string
    {
        return Schema::getConnection()->getDriverName();
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_issues');
    }
};
