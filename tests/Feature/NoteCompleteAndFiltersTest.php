<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\User;
use App\Services\NoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NoteCompleteAndFiltersTest extends TestCase
{
    use RefreshDatabase;

    private NoteService $notes;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notes = app(NoteService::class);
        $this->user = User::create([
            'email' => 'note-filters@example.com',
            'display_name' => 'Note Filters',
            'password' => Hash::make('password'),
            'role' => 'standard',
        ]);
    }

    public function test_default_list_shows_all_months_without_period(): void
    {
        $this->notes->createNote([
            'userId' => $this->user->id,
            'title' => 'July note',
            'body' => 'july',
            'registeredDate' => '2026-07-15',
        ]);
        $this->notes->createNote([
            'userId' => $this->user->id,
            'title' => 'August note',
            'body' => 'august',
            'registeredDate' => '2026-08-01',
        ]);

        $this->actingAs($this->user)
            ->get('/notes')
            ->assertOk()
            ->assertSee('July note', false)
            ->assertSee('August note', false)
            ->assertSee('notes-attach-fab', false)
            ->assertSee('notes-year-dock', false);
    }

    public function test_month_filter_keeps_incomplete_notes_from_other_months(): void
    {
        $july = $this->notes->createNote([
            'userId' => $this->user->id,
            'title' => 'July open',
            'body' => 'open',
            'registeredDate' => '2026-07-10',
        ]);
        $julyDone = $this->notes->createNote([
            'userId' => $this->user->id,
            'title' => 'July done',
            'body' => 'done',
            'registeredDate' => '2026-07-11',
        ]);
        $this->notes->toggleComplete($this->user->id, $julyDone['id']);
        $this->notes->createNote([
            'userId' => $this->user->id,
            'title' => 'August note',
            'body' => 'aug',
            'registeredDate' => '2026-08-02',
        ]);

        $list = $this->notes->listNotes([
            'userId' => $this->user->id,
            'year' => 2026,
            'month' => 8,
            'status' => 'all',
        ]);
        $titles = array_column($list, 'title');

        $this->assertContains('July open', $titles);
        $this->assertContains('August note', $titles);
        $this->assertNotContains('July done', $titles);
        $this->assertSame($july['id'], collect($list)->firstWhere('title', 'July open')['id']);
    }

    public function test_status_filter_pending_and_done(): void
    {
        $open = $this->notes->createNote([
            'userId' => $this->user->id,
            'title' => 'Open',
            'body' => 'o',
            'registeredDate' => '2026-08-01',
        ]);
        $done = $this->notes->createNote([
            'userId' => $this->user->id,
            'title' => 'Done',
            'body' => 'd',
            'registeredDate' => '2026-08-01',
        ]);
        $this->notes->toggleComplete($this->user->id, $done['id']);

        $pending = $this->notes->listNotes([
            'userId' => $this->user->id,
            'status' => 'pending',
        ]);
        $this->assertSame([$open['id']], array_column($pending, 'id'));

        $completed = $this->notes->listNotes([
            'userId' => $this->user->id,
            'status' => 'done',
        ]);
        $this->assertSame([$done['id']], array_column($completed, 'id'));
    }

    public function test_complete_toggle_route(): void
    {
        $note = Note::create([
            'user_id' => $this->user->id,
            'title' => 'Toggle me',
            'body' => 'body',
            'registered_date' => '2026-08-01',
            'completed' => false,
        ]);

        $this->actingAs($this->user)
            ->from('/notes')
            ->post("/notes/{$note->id}/complete", ['returnTo' => '/notes'])
            ->assertRedirect('/notes?notice='.urlencode('メモを完了にしました'));

        $this->assertTrue($note->fresh()->completed);

        $this->actingAs($this->user)
            ->get('/notes')
            ->assertOk()
            ->assertSee('is-completed', false);
    }
}
