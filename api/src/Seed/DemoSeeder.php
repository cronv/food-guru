<?php

declare(strict_types=1);

namespace App\Seed;

use App\Catalog\PublishedRecipes;
use App\Enum\RecipeStatus;
use App\Enum\UserRole;
use App\Support\Slug;
use Doctrine\DBAL\Connection;

/**
 * Основная витрина: сотрудники, рецепты с шагами и картинками, коллекции, отзывы.
 * Затем вызывает остальные демо-сидеры.
 * Всё в одной транзакции: либо загружено полностью, либо ничего.
 */
final class DemoSeeder
{
    public const ADMIN_EMAIL = 'admin@food-guru.local';

    public function __construct(
        private readonly Connection $db,
        private readonly DemoStore $store,
        private readonly CommunityDemo $community,
        private readonly CommerceDemo $commerce,
        private readonly ContestsDemo $contests,
        private readonly JournalDemo $journal,
    ) {
    }

    public function seed(): void
    {
        $this->db->transactional(function (): void {
            [$contentManagerId, $readerId] = $this->staff();
            $categories = $this->categories();
            foreach (['вегетарианское', 'без глютена', 'до 30 минут', 'на ужин', 'сыр', 'грузинская кухня'] as $title) {
                $this->tagId($title);
            }

            $this->recipes($contentManagerId, $categories);
            $this->favorites($readerId);
            $this->collections();
            $this->reviews($readerId);

            $this->community->seed();
            $this->commerce->seed();
            $this->contests->seed();
            $this->journal->seed();
        });
    }

    /** @return array{0: int, 1: int} id контент-менеджера и демо-читателя */
    private function staff(): array
    {
        $verified = $this->store->at();
        $this->store->user('Игорь Петров', self::ADMIN_EMAIL, UserRole::Admin->value, $verified);
        $this->store->user('Ольга Волкова', 'lead@food-guru.local', UserRole::EditorLead->value, $verified);
        $contentManagerId = $this->store->user('Мария Левина', 'manager@food-guru.local', UserRole::ContentManager->value, $verified);
        $readerId = $this->store->user('Анна Ковалёва', 'user@food-guru.local', UserRole::User->value, $verified);

        // Неподтверждённый аккаунт — чтобы было на чём проверять экран подтверждения.
        $this->store->user('Сергей Данилов', 'pending@food-guru.local', UserRole::User->value);

        return [$contentManagerId, $readerId];
    }

    /** @return array<string, int> id категорий по названию */
    private function categories(): array
    {
        $now = $this->store->at();
        $ids = [];
        foreach (['Завтраки', 'Супы', 'Горячее', 'Салаты', 'Выпечка', 'Десерты'] as $i => $title) {
            $this->db->insert('categories', [
                'slug' => Slug::make($title) ?: 'category-'.$i,
                'title' => $title,
                'position' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $ids[$title] = (int) $this->db->lastInsertId();
        }

        return $ids;
    }

    /** Tag::firstOrCreate по адресу: тег с тем же адресом уже есть — берём его. */
    private function tagId(string $title): int
    {
        $slug = Slug::make($title) ?: bin2hex(random_bytes(4));
        $id = $this->db->fetchOne('SELECT id FROM tags WHERE slug = ?', [$slug]);
        if (false !== $id) {
            return (int) $id;
        }

        $now = $this->store->at();
        $this->db->insert('tags', ['slug' => $slug, 'title' => $title, 'created_at' => $now, 'updated_at' => $now]);

        return (int) $this->db->lastInsertId();
    }

    /** @param array<string, int> $categories */
    private function recipes(int $authorId, array $categories): void
    {
        $samples = $this->store->json('recipes.json');
        $stepTabs = $this->store->json('step-tabs.json');

        foreach ($samples as $i => $sample) {
            $status = RecipeStatus::from($sample['status']);
            $now = $this->store->at();

            $this->db->insert('recipes', [
                'author_id' => $authorId,
                'category_id' => $categories[$sample['category']] ?? null,
                'slug' => $sample['slug'],
                'title' => $sample['title'],
                '`lead`' => $sample['lead'],
                'time_minutes' => $sample['minutes'],
                'servings' => $sample['servings'],
                'difficulty' => $sample['difficulty'],
                'status' => $status->value,
                'published_at' => RecipeStatus::Published === $status ? $this->store->at("-{$i} days") : null,
                'og_description' => $sample['title'].' — пошаговый рецепт.',
                'calories' => $sample['nutrition']['calories'],
                'protein' => $sample['nutrition']['protein'],
                'fat' => $sample['nutrition']['fat'],
                'carbs' => $sample['nutrition']['carbs'],
                'is_vegetarian' => (int) $sample['vegetarian'],
                'is_gluten_free' => (int) $sample['gluten_free'],
                'moderation_note' => RecipeStatus::Rejected === $status
                    ? 'Уточните граммовку маскарпоне и добавьте фото финального шага.'
                    : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $recipeId = (int) $this->db->lastInsertId();

            $tagIds = array_unique(array_map(fn (string $title): int => $this->tagId($title), $sample['tags']));
            foreach ($tagIds as $tagId) {
                $this->db->insert('recipe_tag', ['recipe_id' => $recipeId, 'tag_id' => $tagId]);
            }

            // У рецептов на модерации и в черновиках текста в прототипе нет — заглушки для редактора.
            $ingredients = $sample['ingredients'] ?: array_map(
                static fn (int $n): array => ['name' => 'Ингредиент '.$n, 'amount' => 50 * $n / $sample['servings'], 'unit' => 'г'],
                range(1, 4),
            );
            $steps = $sample['steps'] ?: array_map(
                static fn (int $n): array => ['body' => 'Шаг '.$n.'. Текст-заглушка для разработки.', 'hint' => null],
                range(1, 3),
            );

            foreach ($ingredients as $position => $row) {
                $this->db->insert('recipe_ingredients', [
                    'recipe_id' => $recipeId,
                    'name' => $row['name'],
                    'amount' => $row['amount'],
                    'unit' => $row['unit'],
                    'position' => $position + 1,
                ]);
            }

            foreach ($steps as $position => $row) {
                $tabs = $stepTabs[$sample['slug']][(string) ($position + 1)] ?? [];
                $this->db->insert('recipe_steps', [
                    'recipe_id' => $recipeId,
                    'position' => $position + 1,
                    'body' => $row['body'],
                    'hint' => $row['hint'],
                    'tip' => $tabs['tip'] ?? null,
                    'substitution' => $tabs['substitution'] ?? null,
                    'chef_secret' => $tabs['chef_secret'] ?? null,
                ]);
            }

            $this->dishArt($recipeId, $sample['slug'], $sample['title'], $authorId);
            $this->reel($recipeId, $sample['slug'], $sample['reel'] ?? null);
        }
    }

    /** Обложка и два кадра галереи из нарисованных иллюстраций. Нет файла — рецепт без картинок. */
    private function dishArt(int $recipeId, string $slug, string $title, int $uploaderId): void
    {
        $frames = [
            'cover' => 'Готовое блюдо',
            'ingredients' => 'Ингредиенты',
            'process' => 'В процессе',
        ];

        $gallery = [];
        foreach ($frames as $kind => $caption) {
            $source = $this->store->asset("dishes/{$slug}-{$kind}.svg");
            if (!is_file($source)) {
                continue;
            }

            $path = "dishes/{$slug}-{$kind}.svg";
            $this->store->publishFile($source, $path);
            $mediaId = $this->store->mediaRow($path, $uploaderId, 1200, 900, (int) filesize($source), 'image/svg+xml', "{$title} — {$caption}");

            if ('cover' === $kind) {
                $this->db->update('recipes', ['cover_media_id' => $mediaId], ['id' => $recipeId]);
            } else {
                $gallery[] = ['media_id' => $mediaId, 'caption' => $caption, 'position' => \count($gallery)];
            }
        }

        foreach ($gallery as $row) {
            $this->db->insert('recipe_media', ['recipe_id' => $recipeId] + $row);
        }
    }

    /** Видеорецепт: сначала <slug>.mp4, затем файл под именем из прототипа. */
    private function reel(int $recipeId, string $slug, ?string $named): void
    {
        foreach (array_filter(["{$slug}.mp4", $named]) as $file) {
            $source = $this->store->asset("reels/{$file}");
            if (!is_file($source)) {
                continue;
            }

            $path = "reels/{$file}";
            $this->store->publishFile($source, $path);
            $this->db->update('recipes', ['reel_path' => $path], ['id' => $recipeId]);

            return;
        }
    }

    private function favorites(int $readerId): void
    {
        $ids = $this->db->fetchFirstColumn(
            'SELECT r.id FROM recipes r WHERE '.PublishedRecipes::SQL.' ORDER BY r.id LIMIT 3',
            ['now' => $this->store->at()],
        );

        foreach ($ids as $recipeId) {
            $this->db->insert('favorites', ['user_id' => $readerId, 'recipe_id' => (int) $recipeId, 'created_at' => $this->store->at()]);
        }
    }

    private function collections(): void
    {
        $sets = [
            ['Что приготовить в октябре', 'oktyabr',
                'Тыква, грибы и чечевица — всё, что сейчас стоит дёшево и пахнет как надо.',
                ['Тыквенный крем-суп с хрустящим нутом', 'Грибной суп со сливками', 'Чечевичный суп с томатами']],
            ['Ужин на одной сковороде', 'odna-skovoroda',
                'Когда мыть три кастрюли нет сил. Всё готовится в одной посуде.',
                ['Шакшука с фетой', 'Бёдра в медово-горчичном соусе', 'Паста качо-э-пепе']],
            ['Завтраки выходного дня', 'zavtraki',
                'То, ради чего стоит встать на полчаса раньше.',
                ['Сырники на рисовой муке', 'Шакшука с фетой']],
            ['Паста на ужин за полчаса', 'pasta-na-uzhin',
                'Пока варятся спагетти, готов и соус.',
                ['Паста с креветками в томатно-сливочном соусе', 'Паста качо-э-пепе']],
            ['Без глютена и без сложностей', 'bez-glyutena',
                'Обычная домашняя еда, в которой просто нет пшеничной муки.',
                ['Сырники на рисовой муке', 'Киноа с печёной свёклой и фетой', 'Бёдра в медово-горчичном соусе', 'Чечевичный суп с томатами']],
            ['Сладкое, ради которого стоит включить духовку', 'sladkoe',
                'Десерты с граммовками до грамма — чтобы получилось с первого раза.',
                ['Шоколадный фондан с жидким центром', 'Сырники на рисовой муке']],
        ];

        $now = $this->store->at();
        foreach ($sets as [$title, $slug, $lead, $titles]) {
            $this->db->insert('collections', [
                'slug' => $slug,
                'title' => $title,
                '`lead`' => $lead,
                'is_published' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $collectionId = (int) $this->db->lastInsertId();

            $ids = $this->db->fetchFirstColumn(
                'SELECT id FROM recipes WHERE title IN ('.implode(',', array_fill(0, \count($titles), '?')).') ORDER BY id',
                $titles,
            );
            foreach ($ids as $position => $recipeId) {
                $this->db->insert('collection_recipe', [
                    'collection_id' => $collectionId,
                    'recipe_id' => (int) $recipeId,
                    'position' => $position,
                ]);
            }
        }
    }

    private function reviews(int $readerId): void
    {
        $samples = [
            ['Тыквенный крем-суп с хрустящим нутом', 5,
                'Нут действительно стоит обжарить отдельно — без него суп получается скучным.'],
            ['Паста качо-э-пепе', 4,
                'С первого раза сыр свернулся комками. Со второго понял: снимать с огня до того, как добавлять.'],
            ['Сырники на рисовой муке', 5,
                'Протереть творог через сито — шаг, который правда нельзя пропускать.'],
        ];

        foreach ($samples as [$title, $rating, $body]) {
            $recipeId = $this->db->fetchOne('SELECT id FROM recipes WHERE title = ? ORDER BY id LIMIT 1', [$title]);
            if (false === $recipeId) {
                continue;
            }

            $now = $this->store->at();
            $this->db->insert('reviews', [
                'recipe_id' => (int) $recipeId,
                'user_id' => $readerId,
                'rating' => $rating,
                'body' => $body,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
