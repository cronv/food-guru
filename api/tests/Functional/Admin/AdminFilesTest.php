<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Загрузка файлов в админке: PDF журнала с обложкой и видеоролик рецепта. */
final class AdminFilesTest extends ApiTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 400]/Contents 4 0 R/Resources<<>>>>endobj\n4 0 obj<</Length 0>>stream\n\nendstream endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    public function testJournalPdfUploadStoresFileAndRendersCover(): void
    {
        $manager = $this->user(UserRole::ContentManager);
        $issueId = $this->issueBy($manager);
        $this->login($manager);

        $this->upload('/api/v1/admin/journal/'.$issueId.'/file', 'file', $this->tempFile('issue.pdf', self::PDF, 'application/pdf'));

        self::assertResponseIsSuccessful();
        $issue = $this->db()->fetchAssociative('SELECT file_path, file_name, cover_media_id FROM journal_issues WHERE id = ?', [$issueId]);
        self::assertIsArray($issue);
        self::assertSame('issue.pdf', $issue['file_name']);
        self::assertNotEmpty($issue['file_path']);
        self::assertFileExists('/tmp/food-guru-test-storage/private/'.$issue['file_path']);
        self::assertNotNull($issue['cover_media_id']);
    }

    public function testJournalUploadRejectsNonPdf(): void
    {
        $manager = $this->user(UserRole::ContentManager);
        $issueId = $this->issueBy($manager);
        $this->login($manager);

        $body = $this->upload('/api/v1/admin/journal/'.$issueId.'/file', 'file', $this->tempFile('issue.pdf', 'plain text', 'text/plain'));

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('file', $body['errors']);
    }

    public function testReelUploadStoresVideoAndReturnsUrl(): void
    {
        $manager = $this->user(UserRole::ContentManager);
        $recipeId = $this->recipeBy($manager);
        $this->login($manager);

        $mp4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64);
        $body = $this->upload('/api/v1/admin/recipes/'.$recipeId.'/reel', 'file', $this->tempFile('reel.mp4', $mp4, 'video/mp4'));

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('http', $body['data']['reel_url']);
        $path = $this->db()->fetchOne('SELECT reel_path FROM recipes WHERE id = ?', [$recipeId]);
        self::assertIsString($path);
        self::assertStringStartsWith('reels/', $path);
    }

    /** @return array<string, mixed> */
    private function upload(string $uri, string $field, UploadedFile $file): array
    {
        $this->client->request('POST', $uri, files: [$field => $file], server: $this->xsrf() + [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => self::SITE,
        ]);

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function tempFile(string $name, string $content, string $mime): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(6)).'-'.$name;
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, $mime, null, true);
    }

    private function issueBy(User $author): int
    {
        $this->db()->insert('journal_issues', [
            'author_id' => (int) $author->getId(),
            'slug' => 'issue-'.bin2hex(random_bytes(4)),
            'title' => 'Гайд недели',
            'period' => 'week',
            'issue_date' => '2026-11-02',
            'issue_year' => 2026,
            'issue_month' => 11,
            'status' => 'draft',
            'downloads_count' => 0,
            'created_at' => '2026-11-10 10:00:00',
            'updated_at' => '2026-11-10 10:00:00',
        ]);

        return (int) $this->db()->lastInsertId();
    }

    private function recipeBy(User $author): int
    {
        $this->db()->insert('recipes', [
            'author_id' => (int) $author->getId(),
            'category_id' => null,
            'slug' => 'reel-'.bin2hex(random_bytes(4)),
            'title' => 'Ролик',
            'status' => 'draft',
            'published_at' => null,
        ]);

        return (int) $this->db()->lastInsertId();
    }
}
