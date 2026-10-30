<?php

namespace Database\Seeders;

use App\Enums\AcademyFormat;
use App\Enums\AcademyLevel;
use App\Enums\ContestKind;
use App\Enums\EntryStatus;
use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Models\AcademySession;
use App\Models\Contest;
use App\Models\ContestEntry;
use App\Models\User;
use App\Services\RatingService;
use Illuminate\Database\Seeder;

/**
 * Демонстрационные конкурсы, занятия академии и баллы.
 * Нужен только витрине: на боевом сайте не запускается.
 */
class ContestsAcademySeeder extends Seeder
{
    public function run(): void
    {
        $editor = User::where('role', UserRole::EditorLead)->first()
            ?? User::where('role', UserRole::Admin)->first();

        $readers = User::where('role', UserRole::User)
            ->whereNotNull('email_verified_at')
            ->get();

        if (! $editor) {
            return;
        }

        $this->seedContests($editor, $readers);
        $this->seedAcademy($editor);
        $this->seedRating($readers);
    }

    private function seedContests(User $editor, $readers): void
    {
        // Идёт приём работ.
        $photo = Contest::create([
            'author_id' => $editor->id,
            'slug' => 'luchshee-foto-2026',
            'title' => 'Лучшее фото блюда 2026',
            'lead' => 'Снимите то, что приготовили по рецепту с сайта, и покажите, как это выглядит у вас дома.',
            'description' => "Нам интереснее живая кухня, чем студийный свет.\n\nПрисылайте снимок блюда, которое вы правда готовили: на своей тарелке, при своём освещении, со своими недочётами. Жюри смотрит на то, хочется ли это съесть, а не на качество камеры.",
            'rules' => "Один участник — одна работа.\nФото должно быть сделано вами.\nБлюдо приготовлено по любому рецепту с food-guru.ru.\nОбработка допустима, подмена блюда — нет.",
            'prize' => 'Набор чугунной посуды и разбор снимка от фуд-фотографа',
            'kind' => ContestKind::Photo,
            'status' => PublishStatus::Published,
            'submissions_open_at' => now()->subDays(10),
            'submissions_close_at' => now()->addDays(14),
            'voting_open_at' => now()->addDays(14),
            'voting_close_at' => now()->addDays(24),
            'results_at' => now()->addDays(26),
            'published_at' => now()->subDays(12),
            'max_entries_per_user' => 1,
        ]);

        // Идёт голосование.
        $video = Contest::create([
            'author_id' => $editor->id,
            'slug' => 'video-recept-za-minutu',
            'title' => 'Видеорецепт за минуту',
            'lead' => 'Покажите рецепт целиком за шестьдесят секунд — от доски до тарелки.',
            'description' => "Формат простой: вертикальное видео, одна минута, никакой озвучки не нужно.\n\nСмотрим на то, понятно ли по видео повторить рецепт.",
            'rules' => "Вертикальное видео 9:16.\nДлительность до 60 секунд.\nМузыка не обязательна — звук кухни тоже подходит.",
            'prize' => 'Публикация в разделе рилсов и годовая подписка на курсы академии',
            'kind' => ContestKind::Video,
            'status' => PublishStatus::Published,
            'submissions_open_at' => now()->subDays(40),
            'submissions_close_at' => now()->subDays(6),
            'voting_open_at' => now()->subDays(5),
            'voting_close_at' => now()->addDays(5),
            'results_at' => now()->addDays(7),
            'published_at' => now()->subDays(42),
        ]);

        // Завершённый — чтобы архив не был пустым.
        $past = Contest::create([
            'author_id' => $editor->id,
            'slug' => 'zimnyaya-vypechka-2025',
            'title' => 'Зимняя выпечка 2025',
            'lead' => 'Итоги подведены: сто сорок две работы, три победителя.',
            'description' => 'Конкурс завершён. Спасибо всем, кто прислал работы — читать их было вкуснее, чем смотреть.',
            'prize' => 'Набор форм для выпечки',
            'kind' => ContestKind::Photo,
            'status' => PublishStatus::Published,
            'submissions_open_at' => now()->subMonths(10),
            'submissions_close_at' => now()->subMonths(9),
            'voting_open_at' => now()->subMonths(9),
            'voting_close_at' => now()->subMonths(8)->subDays(20),
            'results_at' => now()->subMonths(8)->subDays(18),
            'published_at' => now()->subMonths(10)->subDays(2),
        ]);

        if ($readers->isEmpty()) {
            return;
        }

        $samples = [
            [$photo, 'Шакшука в воскресенье', 'Готовил по рецепту с сайта, добавил только фету сверху.', EntryStatus::Approved, 0, null],
            [$photo, 'Тыквенный суп на балконе', 'Снял при дневном свете, без вспышки.', EntryStatus::Pending, 0, null],
            [$video, 'Паста за минуту', 'Снимал на штатив, склейка в четыре кадра.', EntryStatus::Approved, 34, null],
            [$video, 'Сырники, как у бабушки', 'Самое сложное было уложиться в минуту.', EntryStatus::Approved, 21, null],
            [$past, 'Имбирное печенье', 'Расписывала глазурью два вечера.', EntryStatus::Approved, 88, 1],
            [$past, 'Штоллен', 'Выдерживал три недели, как положено.', EntryStatus::Approved, 61, 2],
        ];

        foreach ($samples as $i => [$contest, $title, $body, $status, $votes, $place]) {
            $entry = ContestEntry::create([
                'contest_id' => $contest->id,
                'user_id' => $readers[$i % $readers->count()]->id,
                'title' => $title,
                'body' => $body,
                'status' => $status,
                'place' => $place,
            ]);

            if ($votes > 0) {
                $entry->forceFill(['votes_count' => $votes])->save();
            }

            $contest->increment('entries_count');

            if ($votes > 0) {
                $contest->increment('votes_count', $votes);
            }
        }
    }

    private function seedAcademy(User $editor): void
    {
        AcademySession::create([
            'author_id' => $editor->id,
            'slug' => 'nozh-i-doska-bazovye-narezki',
            'title' => 'Нож и доска: базовые нарезки',
            'lead' => 'Разберём хват, четыре основные нарезки и почему тупой нож опаснее острого.',
            'description' => "Полтора часа практики. Возьмите нож, доску, две луковицы и морковь — будем резать вместе.\n\nВопросы можно задавать в чате во время эфира.",
            'takeaways' => "Держать нож так, чтобы рука не уставала\nНарезать лук без слёз и без риска\nПонимать, когда нужна соломка, а когда кубик\nТочить нож дома, без станка",
            'format' => AcademyFormat::Webinar,
            'level' => AcademyLevel::Basic,
            'status' => PublishStatus::Published,
            'host_name' => 'Мария Левина',
            'host_title' => 'Шеф-повар, ведёт курс базовой техники',
            'starts_at' => now()->addDays(6)->setTime(19, 0),
            'duration_minutes' => 90,
            'stream_url' => 'https://example.com/stream/knife-basics',
            'published_at' => now()->subDays(8),
        ]);

        AcademySession::create([
            'author_id' => $editor->id,
            'slug' => 'tyoplye-salaty-zimoy',
            'title' => 'Тёплые салаты зимой',
            'lead' => 'Прямой эфир с рынка и сразу на кухню: собираем три салата из того, что есть в январе.',
            'description' => 'Эфир идёт без монтажа. Начинаем на рынке, заканчиваем за столом.',
            'takeaways' => "Выбирать корнеплоды зимой\nБалансировать кислоту и жир в заправке\nПодавать салат тёплым, не горячим",
            'format' => AcademyFormat::Stream,
            'level' => AcademyLevel::Medium,
            'status' => PublishStatus::Published,
            'host_name' => 'Ольга Волкова',
            'host_title' => 'Редактор раздела «Горячее»',
            'starts_at' => now()->subDays(12)->setTime(18, 30),
            'duration_minutes' => 60,
            'record_url' => 'https://example.com/record/warm-salads',
            'published_at' => now()->subDays(20),
        ]);

        AcademySession::create([
            'author_id' => $editor->id,
            'slug' => 'testo-dlya-pasty-s-nulya',
            'title' => 'Тесто для пасты с нуля',
            'lead' => 'Видеоурок на 40 минут: мука, яйца, руки и скалка. Без машинки.',
            'description' => 'Запись доступна сразу после регистрации. Можно останавливать и повторять за ведущим.',
            'takeaways' => "Соотношение муки и яиц, которое работает\nКак понять, что тесто вымешано\nСколько тесту отдыхать и почему\nРаскатать скалкой до нужной толщины",
            'format' => AcademyFormat::Lesson,
            'level' => AcademyLevel::Advanced,
            'status' => PublishStatus::Published,
            'host_name' => 'Игорь Петров',
            'host_title' => 'Ведёт курс итальянской кухни',
            'duration_minutes' => 40,
            'record_url' => 'https://example.com/record/pasta-dough',
            'published_at' => now()->subDays(30),
        ]);

        // Черновик — чтобы в админке было что готовить к публикации.
        AcademySession::create([
            'author_id' => $editor->id,
            'slug' => 'ferment-doma',
            'title' => 'Ферментация дома: с чего начать',
            'lead' => 'Готовится к публикации.',
            'format' => AcademyFormat::Webinar,
            'level' => AcademyLevel::Medium,
            'status' => PublishStatus::Draft,
            'host_name' => 'Мария Левина',
        ]);
    }

    /** Немного баллов, чтобы таблица лидеров не была пустой. */
    private function seedRating($readers): void
    {
        if ($readers->isEmpty()) {
            return;
        }

        $rating = app(RatingService::class);

        foreach ($readers as $user) {
            $rating->recalculate($user);
        }
    }
}
