<?php

declare(strict_types=1);

namespace App\Admin;

use App\Support\Slug;
use Doctrine\DBAL\Connection;

final class RecipeWriter
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Slug::make($source) ?: 'recipe';
        $slug = $base;
        $i = 2;
        while (true) {
            $exists = (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM recipes WHERE slug = ?'.($ignoreId ? ' AND id != ?' : ''),
                $ignoreId ? [$slug, $ignoreId] : [$slug]
            );
            if (0 === $exists) {
                return $slug;
            }
            $slug = $base.'-'.$i++;
        }
    }

    /** @param array<string, mixed> $data */
    public function syncChildren(int $recipeId, array $data): void
    {
        if (isset($data['ingredients']) && \is_array($data['ingredients'])) {
            $this->db->executeStatement('DELETE FROM recipe_ingredients WHERE recipe_id = ?', [$recipeId]);
            foreach (array_values($data['ingredients']) as $i => $row) {
                $this->db->insert('recipe_ingredients', [
                    'recipe_id' => $recipeId,
                    'name' => (string) $row['name'],
                    'amount' => $row['amount'] ?? null,
                    'unit' => $row['unit'] ?? null,
                    'position' => $i,
                ]);
            }
        }

        if (isset($data['steps']) && \is_array($data['steps'])) {
            $this->db->executeStatement('DELETE FROM recipe_steps WHERE recipe_id = ?', [$recipeId]);
            foreach (array_values($data['steps']) as $i => $row) {
                $this->db->insert('recipe_steps', [
                    'recipe_id' => $recipeId,
                    'media_id' => $row['media_id'] ?? null,
                    'position' => $i,
                    'body' => (string) $row['body'],
                    'hint' => $row['hint'] ?? null,
                    'tip' => $row['tip'] ?? null,
                    'substitution' => $row['substitution'] ?? null,
                    'chef_secret' => $row['chef_secret'] ?? null,
                ]);
            }
        }

        if (isset($data['tags']) && \is_array($data['tags'])) {
            $this->db->executeStatement('DELETE FROM recipe_tag WHERE recipe_id = ?', [$recipeId]);
            foreach ($data['tags'] as $title) {
                $slug = Slug::make((string) $title) ?: bin2hex(random_bytes(4));
                $tagId = $this->db->fetchOne('SELECT id FROM tags WHERE slug = ?', [$slug]);
                if (false === $tagId) {
                    $this->db->insert('tags', ['slug' => $slug, 'title' => (string) $title]);
                    $tagId = $this->db->lastInsertId();
                }
                $this->db->executeStatement(
                    'INSERT IGNORE INTO recipe_tag (recipe_id, tag_id) VALUES (?, ?)',
                    [$recipeId, (int) $tagId]
                );
            }
        }

        if (isset($data['gallery']) && \is_array($data['gallery'])) {
            $this->db->executeStatement('DELETE FROM recipe_media WHERE recipe_id = ?', [$recipeId]);
            foreach (array_values($data['gallery']) as $i => $row) {
                $this->db->insert('recipe_media', [
                    'recipe_id' => $recipeId,
                    'media_id' => (int) $row['media_id'],
                    'caption' => $row['caption'] ?? null,
                    'position' => $i,
                ]);
            }
        }
    }
}
