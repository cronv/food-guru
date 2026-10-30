<?php

namespace Tests\Feature;

use App\Enums\JournalPeriod;
use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Models\JournalIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JournalDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function issue(array $overrides = []): JournalIssue
    {
        Storage::fake('local');
        Storage::disk('local')->put('journal/guide.pdf', '%PDF-1.4 fake');

        $issue = new JournalIssue(array_merge([
            'slug' => 'gayd-mesyaca-yanvar',
            'title' => 'Гайд месяца: январь',
            'period' => JournalPeriod::Month,
            'issue_date' => '2026-01-01',
            'status' => PublishStatus::Published,
            'published_at' => now()->subDay(),
        ], $overrides));

        $issue->syncPeriodColumns();
        $issue->save();

        $issue->forceFill([
            'file_path' => 'journal/guide.pdf',
            'file_disk' => 'local',
            'file_name' => 'original.pdf',
            'file_size' => 13,
        ])->save();

        return $issue->refresh();
    }

    public function test_опубликованный_выпуск_скачивается_и_счётчик_растёт(): void
    {
        $issue = $this->issue();

        $this->get("/api/v1/journal/{$issue->slug}/download")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertSame(1, $issue->fresh()->downloads_count);
    }

    public function test_имя_файла_берётся_из_заголовка_выпуска(): void
    {
        $issue = $this->issue();

        $response = $this->get("/api/v1/journal/{$issue->slug}/download")->assertOk();

        $this->assertStringContainsString('.pdf', $response->headers->get('content-disposition'));
    }

    public function test_неопубликованный_выпуск_не_скачивается(): void
    {
        $issue = $this->issue(['status' => PublishStatus::Draft, 'published_at' => null]);

        $this->get("/api/v1/journal/{$issue->slug}/download")->assertNotFound();
        $this->assertSame(0, $issue->fresh()->downloads_count);
    }

    public function test_выпуск_без_файла_отдаёт_404(): void
    {
        $issue = $this->issue();
        $issue->forceFill(['file_path' => null])->save();

        $this->get("/api/v1/journal/{$issue->slug}/download")->assertNotFound();
    }

    public function test_год_и_месяц_заполняются_из_даты_выпуска(): void
    {
        $issue = $this->issue(['issue_date' => '2026-03-19', 'period' => JournalPeriod::Month]);

        $this->assertSame(2026, $issue->issue_year);
        $this->assertSame(3, $issue->issue_month);
        $this->assertSame('2026-03-01', $issue->issue_date->toDateString());
    }

    public function test_фильтр_по_году_и_месяцу_отбирает_нужные_выпуски(): void
    {
        $this->issue(['slug' => 'yanvar', 'issue_date' => '2026-01-01']);
        $this->issue(['slug' => 'fevral', 'issue_date' => '2026-02-01']);
        $this->issue(['slug' => 'proshlyy-god', 'issue_date' => '2025-11-01']);

        $this->getJson('/api/v1/journal?year=2026')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/journal?year=2026&month=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'fevral');
    }

    public function test_справочник_периодов_группирует_по_годам(): void
    {
        $this->issue(['slug' => 'a', 'issue_date' => '2026-01-01']);
        $this->issue(['slug' => 'b', 'issue_date' => '2026-02-01']);
        $this->issue(['slug' => 'c', 'issue_date' => '2025-11-01']);

        $response = $this->getJson('/api/v1/journal/periods')->assertOk();

        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('data.0.year', 2026);
        $response->assertJsonPath('data.0.count', 2);
        $response->assertJsonPath('data.1.year', 2025);
    }

    public function test_выпуск_без_файла_нельзя_опубликовать(): void
    {
        $issue = $this->issue(['status' => PublishStatus::Draft, 'published_at' => null]);
        $issue->forceFill(['file_path' => null])->save();

        $this->actingAs($this->user(UserRole::EditorLead))
            ->postJson("/api/v1/admin/journal/{$issue->id}/status/publish")
            ->assertStatus(422)
            ->assertJsonPath('code', 'file_missing');
    }
}
