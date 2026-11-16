<?php

declare(strict_types=1);

namespace App\Seed;

use Doctrine\DBAL\Connection;

/**
 * Демонстрационные конкурсы и занятия академии. Баллы читателям здесь не
 * начисляются отдельно: RatingService уже ведёт rating_points при каждом
 * начислении, пересчитывать из журнала нечего.
 */
final class ContestsDemo
{
    public function __construct(
        private readonly Connection $db,
        private readonly DemoStore $store,
    ) {
    }

    public function seed(): void
    {
        $editorId = $this->editorId();
        if (null === $editorId) {
            return;
        }

        $readers = array_map(
            'intval',
            $this->db->fetchFirstColumn("SELECT id FROM users WHERE role = 'user' AND email_verified_at IS NOT NULL ORDER BY id"),
        );

        $this->contests($editorId, $readers);
        $this->academy($editorId);
    }

    private function editorId(): ?int
    {
        $id = $this->db->fetchOne("SELECT id FROM users WHERE role = 'editor_lead' ORDER BY id LIMIT 1")
            ?: $this->db->fetchOne("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");

        return false === $id ? null : (int) $id;
    }

    /** @param list<int> $readers */
    private function contests(int $editorId, array $readers): void
    {
        // Идёт приём работ.
        $photo = $this->insertContest([
            'author_id' => $editorId,
            'slug' => 'luchshee-foto-2026',
            'title' => 'Лучшее фото блюда 2026',
            '`lead`' => 'Снимите то, что приготовили по рецепту с сайта, и покажите, как это выглядит у вас дома.',
            'description' => "Нам интереснее живая кухня, чем студийный свет.\n\nПрисылайте снимок блюда, которое вы правда готовили: на своей тарелке, при своём освещении, со своими недочётами. Жюри смотрит на то, хочется ли это съесть, а не на качество камеры.",
            'rules' => "Один участник — одна работа.\nФото должно быть сделано вами.\nБлюдо приготовлено по любому рецепту с food-guru.ru.\nОбработка допустима, подмена блюда — нет.",
            'prize' => 'Набор чугунной посуды и разбор снимка от фуд-фотографа',
            'kind' => 'photo',
            'status' => 'published',
            'submissions_open_at' => $this->store->at('-10 days'),
            'submissions_close_at' => $this->store->at('+14 days'),
            'voting_open_at' => $this->store->at('+14 days'),
            'voting_close_at' => $this->store->at('+24 days'),
            'results_at' => $this->store->at('+26 days'),
            'published_at' => $this->store->at('-12 days'),
            'max_entries_per_user' => 1,
        ]);

        // Идёт голосование.
        $video = $this->insertContest([
            'author_id' => $editorId,
            'slug' => 'video-recept-za-minutu',
            'title' => 'Видеорецепт за минуту',
            '`lead`' => 'Покажите рецепт целиком за шестьдесят секунд — от доски до тарелки.',
            'description' => "Формат простой: вертикальное видео, одна минута, никакой озвучки не нужно.\n\nСмотрим на то, понятно ли по видео повторить рецепт.",
            'rules' => "Вертикальное видео 9:16.\nДлительность до 60 секунд.\nМузыка не обязательна — звук кухни тоже подходит.",
            'prize' => 'Публикация в разделе рилсов и годовая подписка на курсы академии',
            'kind' => 'video',
            'status' => 'published',
            'submissions_open_at' => $this->store->at('-40 days'),
            'submissions_close_at' => $this->store->at('-6 days'),
            'voting_open_at' => $this->store->at('-5 days'),
            'voting_close_at' => $this->store->at('+5 days'),
            'results_at' => $this->store->at('+7 days'),
            'published_at' => $this->store->at('-42 days'),
        ]);

        // Завершённый — чтобы архив не был пустым.
        $past = $this->insertContest([
            'author_id' => $editorId,
            'slug' => 'zimnyaya-vypechka-2025',
            'title' => 'Зимняя выпечка 2025',
            '`lead`' => 'Итоги подведены: сто сорок две работы, три победителя.',
            'description' => 'Конкурс завершён. Спасибо всем, кто прислал работы — читать их было вкуснее, чем смотреть.',
            'prize' => 'Набор форм для выпечки',
            'kind' => 'photo',
            'status' => 'published',
            'submissions_open_at' => $this->store->at('-10 months'),
            'submissions_close_at' => $this->store->at('-9 months'),
            'voting_open_at' => $this->store->at('-9 months'),
            'voting_close_at' => $this->store->at('-8 months -20 days'),
            'results_at' => $this->store->at('-8 months -18 days'),
            'published_at' => $this->store->at('-10 months -2 days'),
        ]);

        if ([] === $readers) {
            return;
        }

        // [конкурс, название, текст, статус, голоса, место]
        $samples = [
            [$photo, 'Шакшука в воскресенье', 'Готовил по рецепту с сайта, добавил только фету сверху.', 'approved', 0, null],
            [$photo, 'Тыквенный суп на балконе', 'Снял при дневном свете, без вспышки.', 'pending', 0, null],
            [$video, 'Паста за минуту', 'Снимал на штатив, склейка в четыре кадра.', 'approved', 34, null],
            [$video, 'Сырники, как у бабушки', 'Самое сложное было уложиться в минуту.', 'approved', 21, null],
            [$past, 'Имбирное печенье', 'Расписывала глазурью два вечера.', 'approved', 88, 1],
            [$past, 'Штоллен', 'Выдерживал три недели, как положено.', 'approved', 61, 2],
        ];

        foreach ($samples as $i => [$contestId, $title, $body, $status, $votes, $place]) {
            $now = $this->store->at();
            $this->db->insert('contest_entries', [
                'contest_id' => $contestId,
                'user_id' => $readers[$i % \count($readers)],
                'title' => $title,
                'body' => $body,
                'status' => $status,
                'place' => $place,
                'votes_count' => $votes,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->db->executeStatement('UPDATE contests SET entries_count = entries_count + 1 WHERE id = ?', [$contestId]);
            if ($votes > 0) {
                $this->db->executeStatement('UPDATE contests SET votes_count = votes_count + ? WHERE id = ?', [$votes, $contestId]);
            }
        }
    }

    /** @param array<string, mixed> $row */
    private function insertContest(array $row): int
    {
        $now = $this->store->at();
        $this->db->insert('contests', $row + ['created_at' => $now, 'updated_at' => $now]);

        return (int) $this->db->lastInsertId();
    }

    private function academy(int $editorId): void
    {
        $now = $this->store->at();
        $sessions = [
            [
                'slug' => 'nozh-i-doska-bazovye-narezki',
                'title' => 'Нож и доска: базовые нарезки',
                '`lead`' => 'Разберём хват, четыре основные нарезки и почему тупой нож опаснее острого.',
                'description' => "Полтора часа практики. Возьмите нож, доску, две луковицы и морковь — будем резать вместе.\n\nВопросы можно задавать в чате во время эфира.",
                'takeaways' => "Держать нож так, чтобы рука не уставала\nНарезать лук без слёз и без риска\nПонимать, когда нужна соломка, а когда кубик\nТочить нож дома, без станка",
                'format' => 'webinar',
                'level' => 'basic',
                'status' => 'published',
                'host_name' => 'Мария Левина',
                'host_title' => 'Шеф-повар, ведёт курс базовой техники',
                'starts_at' => $this->store->at('+6 days', '19:00'),
                'duration_minutes' => 90,
                'stream_url' => 'https://example.com/stream/knife-basics',
                'published_at' => $this->store->at('-8 days'),
            ],
            [
                'slug' => 'tyoplye-salaty-zimoy',
                'title' => 'Тёплые салаты зимой',
                '`lead`' => 'Прямой эфир с рынка и сразу на кухню: собираем три салата из того, что есть в январе.',
                'description' => 'Эфир идёт без монтажа. Начинаем на рынке, заканчиваем за столом.',
                'takeaways' => "Выбирать корнеплоды зимой\nБалансировать кислоту и жир в заправке\nПодавать салат тёплым, не горячим",
                'format' => 'stream',
                'level' => 'medium',
                'status' => 'published',
                'host_name' => 'Ольга Волкова',
                'host_title' => 'Редактор раздела «Горячее»',
                'starts_at' => $this->store->at('-12 days', '18:30'),
                'duration_minutes' => 60,
                'record_url' => 'https://example.com/record/warm-salads',
                'published_at' => $this->store->at('-20 days'),
            ],
            [
                'slug' => 'testo-dlya-pasty-s-nulya',
                'title' => 'Тесто для пасты с нуля',
                '`lead`' => 'Видеоурок на 40 минут: мука, яйца, руки и скалка. Без машинки.',
                'description' => 'Запись доступна сразу после регистрации. Можно останавливать и повторять за ведущим.',
                'takeaways' => "Соотношение муки и яиц, которое работает\nКак понять, что тесто вымешано\nСколько тесту отдыхать и почему\nРаскатать скалкой до нужной толщины",
                'format' => 'lesson',
                'level' => 'advanced',
                'status' => 'published',
                'host_name' => 'Игорь Петров',
                'host_title' => 'Ведёт курс итальянской кухни',
                'duration_minutes' => 40,
                'record_url' => 'https://example.com/record/pasta-dough',
                'published_at' => $this->store->at('-30 days'),
            ],
            // Черновик — чтобы в админке было что готовить к публикации.
            [
                'slug' => 'ferment-doma',
                'title' => 'Ферментация дома: с чего начать',
                '`lead`' => 'Готовится к публикации.',
                'format' => 'webinar',
                'level' => 'medium',
                'status' => 'draft',
                'host_name' => 'Мария Левина',
            ],
        ];

        foreach ($sessions as $session) {
            $this->db->insert('academy_sessions', ['author_id' => $editorId] + $session + ['created_at' => $now, 'updated_at' => $now]);
        }
    }
}
