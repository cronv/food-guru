<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Support\Slug;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Запись состава рецепта: ингредиенты, шаги, теги, галерея и адрес — как
 * Запись рецепта. Общая для админки и кабинета читателя:
 * правила не должны расходиться.
 */
final class RecipeWriter
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Ингредиенты, шаги, теги и галерея переписываются целиком — так проще и предсказуемее.
     * Чего нет в $data, того не трогаем.
     *
     * @param array<string, mixed> $data
     */
    public function syncChildren(int $recipeId, array $data): void
    {
        if (isset($data['ingredients']) && \is_array($data['ingredients'])) {
            $this->db->delete('recipe_ingredients', ['recipe_id' => $recipeId]);
            foreach (array_values($data['ingredients']) as $i => $row) {
                $this->db->insert('recipe_ingredients', [
                    'recipe_id' => $recipeId,
                    'name' => $row['name'],
                    'amount' => $row['amount'] ?? null,
                    'unit' => $row['unit'] ?? null,
                    'position' => $i,
                ]);
            }
        }

        if (isset($data['steps']) && \is_array($data['steps'])) {
            $this->db->delete('recipe_steps', ['recipe_id' => $recipeId]);
            foreach (array_values($data['steps']) as $i => $row) {
                $this->db->insert('recipe_steps', [
                    'recipe_id' => $recipeId,
                    'body' => $row['body'],
                    'hint' => $row['hint'] ?? null,
                    'tip' => $row['tip'] ?? null,
                    'substitution' => $row['substitution'] ?? null,
                    'chef_secret' => $row['chef_secret'] ?? null,
                    'media_id' => $row['media_id'] ?? null,
                    'position' => $i,
                ]);
            }
        }

        if (isset($data['tags']) && \is_array($data['tags'])) {
            $ids = [];
            foreach ($data['tags'] as $title) {
                $ids[] = $this->tagId((string) $title);
            }
            $this->db->delete('recipe_tag', ['recipe_id' => $recipeId]);
            foreach (array_unique($ids) as $tagId) {
                $this->db->insert('recipe_tag', ['recipe_id' => $recipeId, 'tag_id' => $tagId]);
            }
        }

        if (isset($data['gallery']) && \is_array($data['gallery'])) {
            // sync(): итог — ровно эти снимки в этом порядке. У повторяющегося id побеждает последний.
            $sync = [];
            foreach (array_values($data['gallery']) as $i => $row) {
                $sync[(int) $row['media_id']] = ['caption' => $row['caption'] ?? null, 'position' => $i];
            }
            $this->db->delete('recipe_media', ['recipe_id' => $recipeId]);
            foreach ($sync as $mediaId => $pivot) {
                $this->db->insert('recipe_media', ['recipe_id' => $recipeId, 'media_id' => $mediaId] + $pivot);
            }
        }
    }

    /** Свободный адрес: «tykvennyi-sup», занят — «tykvennyi-sup-2» и дальше. */
    public function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Slug::make($source) ?: 'recipe';
        $slug = $base;
        $i = 2;
        while (false !== $this->db->fetchOne('SELECT 1 FROM recipes WHERE slug = ?'.(null !== $ignoreId ? ' AND id != ?' : ''), null !== $ignoreId ? [$slug, $ignoreId] : [$slug])) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** Tag::firstOrCreate по адресу: тег с тем же адресом уже есть — берём его. */
    private function tagId(string $title): int
    {
        $slug = Slug::make($title) ?: bin2hex(random_bytes(4));
        $id = $this->db->fetchOne('SELECT id FROM tags WHERE slug = ?', [$slug]);
        if (false !== $id) {
            return (int) $id;
        }

        $now = PublishedRecipes::now($this->clock->now());
        $this->db->insert('tags', ['slug' => $slug, 'title' => $title, 'created_at' => $now, 'updated_at' => $now]);

        return (int) $this->db->lastInsertId();
    }
}
