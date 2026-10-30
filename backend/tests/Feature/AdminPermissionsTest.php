<?php

namespace Tests\Feature;

use App\Enums\ContestKind;
use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Models\Contest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function contest(?int $authorId = null): Contest
    {
        return Contest::create([
            'author_id' => $authorId,
            'slug' => 'konkurs',
            'title' => 'Конкурс',
            'kind' => ContestKind::Photo,
            'status' => PublishStatus::Draft,
            'submissions_open_at' => now()->addDay(),
        ]);
    }

    public function test_обычный_пользователь_в_админку_не_попадает(): void
    {
        $this->actingAs($this->user())->getJson('/api/v1/admin/contests')->assertForbidden();
    }

    public function test_гость_в_админку_не_попадает(): void
    {
        $this->getJson('/api/v1/admin/contests')->assertUnauthorized();
    }

    public function test_контент_менеджер_не_может_публиковать(): void
    {
        $manager = $this->user(UserRole::ContentManager);
        $contest = $this->contest($manager->id);

        $this->actingAs($manager)
            ->postJson("/api/v1/admin/contests/{$contest->id}/status/publish")
            ->assertForbidden();

        $this->assertSame(PublishStatus::Draft, $contest->fresh()->status);
    }

    public function test_руководитель_редакции_публикует(): void
    {
        $contest = $this->contest();

        $this->actingAs($this->user(UserRole::EditorLead))
            ->postJson("/api/v1/admin/contests/{$contest->id}/status/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->assertNotNull($contest->fresh()->published_at);
    }

    public function test_конкурс_без_даты_приёма_работ_не_публикуется(): void
    {
        $contest = $this->contest();
        $contest->forceFill(['submissions_open_at' => null])->save();

        $this->actingAs($this->user(UserRole::EditorLead))
            ->postJson("/api/v1/admin/contests/{$contest->id}/status/publish")
            ->assertStatus(422)
            ->assertJsonPath('code', 'dates_missing');
    }

    public function test_контент_менеджер_видит_в_списке_только_свои_конкурсы(): void
    {
        $manager = $this->user(UserRole::ContentManager);
        $this->contest($manager->id);
        Contest::create([
            'author_id' => $this->user(UserRole::ContentManager)->id,
            'slug' => 'chuzhoy',
            'title' => 'Чужой',
            'kind' => ContestKind::Photo,
            'status' => PublishStatus::Draft,
        ]);

        $this->actingAs($manager)
            ->getJson('/api/v1/admin/contests')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_недопустимый_переход_статуса_отклоняется(): void
    {
        $contest = $this->contest();
        $contest->forceFill(['status' => PublishStatus::Archived])->save();

        $this->actingAs($this->user(UserRole::EditorLead))
            ->postJson("/api/v1/admin/contests/{$contest->id}/status/moderation")
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_transition');
    }

    public function test_даты_конкурса_должны_идти_по_порядку(): void
    {
        $contest = $this->contest();

        $this->actingAs($this->user(UserRole::EditorLead))
            ->patchJson("/api/v1/admin/contests/{$contest->id}", [
                'submissions_open_at' => now()->addDays(5)->toIso8601String(),
                'submissions_close_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('submissions_close_at');
    }

    public function test_пустые_даты_проверку_порядка_не_ломают(): void
    {
        $contest = $this->contest();

        $this->actingAs($this->user(UserRole::EditorLead))
            ->patchJson("/api/v1/admin/contests/{$contest->id}", [
                'voting_close_at' => now()->addDays(10)->toIso8601String(),
            ])
            ->assertOk();
    }
}
