<?php

namespace Tests\Feature;

use App\Enums\ContestKind;
use App\Enums\EntryStatus;
use App\Enums\PublishStatus;
use App\Models\Contest;
use App\Models\ContestEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContestVotingTest extends TestCase
{
    use RefreshDatabase;

    private function contest(array $overrides = []): Contest
    {
        return Contest::create(array_merge([
            'slug' => 'luchshee-foto',
            'title' => 'Лучшее фото',
            'kind' => ContestKind::Photo,
            'status' => PublishStatus::Published,
            'published_at' => now()->subMonth(),
            'submissions_open_at' => now()->subMonth(),
            'submissions_close_at' => now()->subWeek(),
            'voting_open_at' => now()->subDay(),
            'voting_close_at' => now()->addWeek(),
            'max_entries_per_user' => 1,
        ], $overrides));
    }

    private function entry(Contest $contest, User $author): ContestEntry
    {
        return ContestEntry::create([
            'contest_id' => $contest->id,
            'user_id' => $author->id,
            'title' => 'Работа',
            'status' => EntryStatus::Approved,
        ]);
    }

    private function vote(Contest $contest, ContestEntry $entry)
    {
        return $this->postJson("/api/v1/contests/{$contest->slug}/entries/{$entry->id}/vote");
    }

    public function test_голос_засчитывается_и_увеличивает_счётчики(): void
    {
        $contest = $this->contest();
        $entry = $this->entry($contest, $this->user());

        $this->actingAs($this->user())->vote($contest, $entry)->assertOk();

        $this->assertSame(1, $entry->fresh()->votes_count);
        $this->assertSame(1, $contest->fresh()->votes_count);
    }

    public function test_второй_голос_в_том_же_конкурсе_отклоняется(): void
    {
        $contest = $this->contest();
        $first = $this->entry($contest, $this->user());
        $second = $this->entry($contest, $this->user());
        $voter = $this->user();

        $this->actingAs($voter)->vote($contest, $first)->assertOk();
        $this->actingAs($voter)->vote($contest, $second)
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_voted');

        $this->assertSame(1, $contest->fresh()->votes_count, 'Счётчик не должен расти на отклонённом голосе');
    }

    public function test_за_свою_работу_голосовать_нельзя(): void
    {
        $contest = $this->contest();
        $author = $this->user();
        $entry = $this->entry($contest, $author);

        $this->actingAs($author)->vote($contest, $entry)
            ->assertStatus(422)
            ->assertJsonPath('code', 'own_entry');
    }

    public function test_вне_окна_голосования_голос_не_принимается(): void
    {
        $contest = $this->contest([
            'voting_open_at' => now()->addWeek(),
            'voting_close_at' => now()->addWeeks(2),
        ]);
        $entry = $this->entry($contest, $this->user());

        $this->actingAs($this->user())->vote($contest, $entry)
            ->assertStatus(422)
            ->assertJsonPath('code', 'voting_closed');
    }

    public function test_за_работу_на_модерации_голосовать_нельзя(): void
    {
        $contest = $this->contest();
        $entry = $this->entry($contest, $this->user());
        $entry->forceFill(['status' => EntryStatus::Pending])->save();

        $this->actingAs($this->user())->vote($contest, $entry)
            ->assertStatus(422)
            ->assertJsonPath('code', 'entry_not_approved');
    }

    public function test_без_подтверждённой_почты_голосовать_нельзя(): void
    {
        $contest = $this->contest();
        $entry = $this->entry($contest, $this->user());

        $this->actingAs($this->unverified())->vote($contest, $entry)->assertForbidden();
    }

    public function test_гость_не_голосует(): void
    {
        $contest = $this->contest();
        $entry = $this->entry($contest, $this->user());

        $this->vote($contest, $entry)->assertUnauthorized();
    }

    public function test_работа_из_чужого_конкурса_не_принимается(): void
    {
        $contest = $this->contest();
        $other = $this->contest(['slug' => 'drugoy', 'title' => 'Другой']);
        $foreign = $this->entry($other, $this->user());

        $this->actingAs($this->user())->vote($contest, $foreign)->assertNotFound();
    }

    public function test_приём_работ_закрыт_после_дедлайна(): void
    {
        $contest = $this->contest();

        $this->actingAs($this->user())
            ->postJson("/api/v1/contests/{$contest->slug}/entries", [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'submissions_closed');
    }
}
