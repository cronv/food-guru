<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\Functional\ApiTestCase;

/** Доступ к админке: группы middleware и политики. */
final class AdminAccessTest extends ApiTestCase
{
    public function testGuestGets401(): void
    {
        $body = $this->json('GET', '/api/v1/admin/stats');

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['message' => 'Unauthenticated.'], $body);
    }

    public function testPlainUserGets403(): void
    {
        $this->login($this->user());

        $this->json('GET', '/api/v1/admin/recipes');

        self::assertResponseStatusCodeSame(403);
    }

    public function testContentManagerReadsRecipes(): void
    {
        $this->login($this->user(UserRole::ContentManager));

        $this->json('GET', '/api/v1/admin/recipes');

        self::assertResponseIsSuccessful();
    }

    public function testContentManagerCannotReadReports(): void
    {
        $this->login($this->user(UserRole::ContentManager));

        $this->json('GET', '/api/v1/admin/reports');

        self::assertResponseStatusCodeSame(403);
    }

    public function testEditorReadsReports(): void
    {
        $this->login($this->user(UserRole::EditorLead));

        $this->json('GET', '/api/v1/admin/reports');

        self::assertResponseIsSuccessful();
    }

    public function testEditorCannotReadWallet(): void
    {
        $target = $this->user();
        $this->login($this->user(UserRole::EditorLead));

        $this->json('GET', '/api/v1/admin/users/'.$target->getId().'/wallet');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminReadsWallet(): void
    {
        $target = $this->user();
        $this->login($this->user(UserRole::Admin));

        $this->json('GET', '/api/v1/admin/users/'.$target->getId().'/wallet');

        self::assertResponseIsSuccessful();
    }

    public function testContentManagerCannotEditSomeoneElsesRecipe(): void
    {
        $author = $this->user(UserRole::ContentManager);
        $id = $this->recipeBy($author);
        $this->login($this->user(UserRole::ContentManager));

        $this->json('PATCH', '/api/v1/admin/recipes/'.$id, $this->xsrf(), ['title' => 'Чужой рецепт']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testContentManagerEditsOwnRecipe(): void
    {
        $author = $this->user(UserRole::ContentManager);
        $id = $this->recipeBy($author);
        $this->login($author);

        $this->json('PATCH', '/api/v1/admin/recipes/'.$id, $this->xsrf(), ['title' => 'Свой рецепт']);

        self::assertResponseIsSuccessful();
    }

    private function recipeBy(User $author): int
    {
        $this->db()->insert('recipes', [
            'author_id' => (int) $author->getId(),
            'category_id' => null,
            'slug' => 'admin-'.bin2hex(random_bytes(4)),
            'title' => 'Черновик',
            'status' => 'draft',
            'published_at' => null,
        ]);

        return (int) $this->db()->lastInsertId();
    }
}
