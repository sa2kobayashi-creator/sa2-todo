<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\NoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NoteServiceReorderTest extends TestCase
{
    use RefreshDatabase;

    private NoteService $notes;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notes = app(NoteService::class);
        $this->user = User::create([
            'email' => 'note-reorder@example.com',
            'display_name' => 'Note Reorder',
            'password' => Hash::make('password'),
            'role' => 'standard',
        ]);
    }

    public function test_reorder_notes_within_group(): void
    {
        $a = $this->notes->createNote([
            'userId' => $this->user->id,
            'title' => 'A',
            'body' => 'a',
            'type' => 'text',
            'registeredDate' => '2026-07-18',
        ]);
        $b = $this->notes->createNote([
            'userId' => $this->user->id,
            'title' => 'B',
            'body' => 'b',
            'type' => 'text',
            'registeredDate' => '2026-07-18',
        ]);
        $c = $this->notes->createNote([
            'userId' => $this->user->id,
            'title' => 'C',
            'body' => 'c',
            'type' => 'text',
            'registeredDate' => '2026-07-18',
        ]);

        // createNote は先頭に入るので表示順は C, B, A
        $this->assertTrue($this->notes->reorderNotes($this->user->id, [$a['id'], $c['id'], $b['id']]));

        $listed = $this->notes->listNotes(['userId' => $this->user->id, 'archived' => false]);
        $this->assertSame([$a['id'], $c['id'], $b['id']], array_column($listed, 'id'));
    }

    public function test_reorder_rejects_mixed_pinned_group(): void
    {
        $plain = $this->notes->createNote([
            'userId' => $this->user->id,
            'title' => '通常',
            'body' => 'x',
            'type' => 'text',
            'registeredDate' => '2026-07-18',
        ]);
        $pinned = $this->notes->createNote([
            'userId' => $this->user->id,
            'title' => 'ピン',
            'body' => 'y',
            'type' => 'text',
            'pinned' => true,
            'registeredDate' => '2026-07-18',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->notes->reorderNotes($this->user->id, [$plain['id'], $pinned['id']]);
    }

    public function test_new_note_appears_before_existing(): void
    {
        $older = $this->notes->createNote([
            'userId' => $this->user->id,
            'title' => '古い',
            'body' => 'old',
            'type' => 'text',
            'registeredDate' => '2026-07-01',
        ]);
        $newer = $this->notes->createNote([
            'userId' => $this->user->id,
            'title' => '新しい',
            'body' => 'new',
            'type' => 'text',
            'registeredDate' => '2026-07-18',
        ]);

        $listed = $this->notes->listNotes(['userId' => $this->user->id, 'archived' => false]);
        $this->assertSame([$newer['id'], $older['id']], array_column($listed, 'id'));
    }
}
