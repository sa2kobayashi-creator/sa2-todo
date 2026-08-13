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

    public function test_month_filter_shows_only_selected_month_notes(): void
    {
        $this->notes->createNote([
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

        $this->assertSame(['August note'], $titles);

        $this->actingAs($this->user)
            ->get('/notes?period=2026-08')
            ->assertOk()
            ->assertSee('August note', false)
            ->assertDontSee('July open', false)
            ->assertDontSee('July done', false)
            ->assertSee('2026年8月', false);
    }

    public function test_unpinned_section_label_shows_only_when_pinned_notes_exist(): void
    {
        $this->notes->createNote([
            'userId' => $this->user->id,
            'title' => 'Normal note',
            'body' => 'n',
            'registeredDate' => '2026-08-01',
        ]);

        $this->actingAs($this->user)
            ->get('/notes')
            ->assertOk()
            ->assertDontSee('>ピンなし<', false)
            ->assertSee('Normal note', false);

        $pinned = $this->notes->createNote([
            'userId' => $this->user->id,
            'title' => 'Pinned note',
            'body' => 'p',
            'registeredDate' => '2026-08-02',
            'pinned' => true,
        ]);
        $this->assertTrue(! empty($pinned['pinned']));

        $this->actingAs($this->user)
            ->get('/notes')
            ->assertOk()
            ->assertSee('ピン留め', false)
            ->assertSee('ピンなし', false)
            ->assertSee('Pinned note', false)
            ->assertSee('Normal note', false);
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
            ->assertRedirect('/notes')
            ->assertSessionHas('notice', 'メモを完了にしました');

        $this->assertTrue($note->fresh()->completed);

        $this->actingAs($this->user)
            ->get('/notes')
            ->assertOk()
            ->assertSee('is-completed', false);
    }
}
