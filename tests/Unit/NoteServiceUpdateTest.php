<?php

namespace Tests\Unit;

use App\Models\Note;
use App\Models\User;
use App\Services\NoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NoteServiceUpdateTest extends TestCase
{
    use RefreshDatabase;

    private NoteService $notes;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notes = app(NoteService::class);
        $this->user = User::create([
            'email' => 'note-update@example.com',
            'display_name' => 'Note Update',
            'password' => Hash::make('password'),
            'role' => 'standard',
        ]);
    }

    public function test_update_category_preserves_text_body(): void
    {
        $created = $this->notes->createNote([
            'userId' => $this->user->id,
            'title' => 'タイトル',
            'body' => "本文が残るはず\n2行目",
            'type' => 'text',
            'category' => 'personal',
            'registeredDate' => '2026-07-17',
        ]);

        $updated = $this->notes->updateNote($this->user->id, $created['id'], [
            'title' => 'タイトル',
            'body' => "本文が残るはず\n2行目",
            'color' => 'default',
            'category' => 'work',
            'type' => 'text',
            'items' => null,
            'registeredDate' => '2026-07-17',
        ]);

        $this->assertNotNull($updated);
        $this->assertSame('work', $updated['category']);
        $this->assertSame('text', $updated['type']);
        $this->assertSame("本文が残るはず\n2行目", $updated['body']);
        $this->assertSame([], $updated['items']);
    }

    public function test_update_checklist_keeps_items(): void
    {
        $note = Note::create([
            'user_id' => $this->user->id,
            'title' => 'リスト',
            'body' => '',
            'type' => 'checklist',
            'category' => 'personal',
            'items' => [
                ['id' => 1, 'text' => '項目A', 'checked' => false],
                ['id' => 2, 'text' => '項目B', 'checked' => true],
            ],
            'registered_date' => '2026-07-17',
        ]);

        $updated = $this->notes->updateNote($this->user->id, $note->id, [
            'title' => 'リスト',
            'category' => 'idea',
            'type' => 'checklist',
            'items' => [
                ['text' => '項目A', 'checked' => '0'],
                ['text' => '項目B', 'checked' => '1'],
            ],
            'registeredDate' => '2026-07-17',
        ]);

        $this->assertSame('idea', $updated['category']);
        $this->assertSame('checklist', $updated['type']);
        $this->assertCount(2, $updated['items']);
        $this->assertSame('項目A', $updated['items'][0]['text']);
        $this->assertSame('項目B', $updated['items'][1]['text']);
    }

    public function test_update_to_text_type_preserves_body_and_clears_items(): void
    {
        $note = Note::create([
            'user_id' => $this->user->id,
            'title' => '健康',
            'body' => '',
            'type' => 'checklist',
            'category' => 'idea',
            'items' => [
                ['id' => 1, 'text' => '項目A', 'checked' => false],
            ],
            'registered_date' => '2026-07-17',
        ]);

        $updated = $this->notes->updateNote($this->user->id, $note->id, [
            'title' => '健康',
            'body' => 'フリーメモ本文',
            'category' => 'idea',
            'type' => 'text',
            'registeredDate' => '2026-07-17',
        ]);

        $this->assertSame('text', $updated['type']);
        $this->assertSame('フリーメモ本文', $updated['body']);
        $this->assertSame([], $updated['items']);
    }
}
