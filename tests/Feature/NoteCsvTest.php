<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NoteCsvTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'email' => 'notes-csv@example.com',
            'display_name' => 'Notes CSV',
            'password' => Hash::make('password123'),
            'role' => UserRole::Standard,
        ]);
    }

    public function test_export_and_import_notes_csv_round_trip(): void
    {
        $user = $this->makeUser();
        Note::create([
            'user_id' => $user->id,
            'title' => '買い物',
            'body' => '牛乳を買う',
            'color' => 'mint',
            'pinned' => true,
            'sort_order' => 1,
            'archived' => false,
            'completed' => false,
            'type' => 'text',
            'category' => 'personal',
            'items' => [],
            'registered_date' => '2026-08-01',
        ]);
        Note::create([
            'user_id' => $user->id,
            'title' => 'やること',
            'body' => '',
            'color' => 'default',
            'pinned' => false,
            'sort_order' => 2,
            'archived' => false,
            'completed' => true,
            'type' => 'checklist',
            'category' => 'work',
            'items' => [
                ['id' => 1, 'text' => '報告する', 'checked' => true],
                ['id' => 2, 'text' => '返信する', 'checked' => false],
            ],
            'registered_date' => '2026-08-02',
        ]);

        $export = $this->actingAs($user)->get('/notes/export');
        $export->assertOk();
        $csv = $export->streamedContent();
        $this->assertStringContainsString('タイトル', $csv);
        $this->assertStringContainsString('買い物', $csv);
        $this->assertStringContainsString('やること', $csv);

        Note::query()->where('user_id', $user->id)->delete();
        $this->assertSame(0, Note::query()->where('user_id', $user->id)->count());

        $file = UploadedFile::fake()->createWithContent('notes.csv', $csv);
        $this->actingAs($user)->post('/notes/import', [
            'csv_file' => $file,
            'returnTo' => '/notes',
        ])->assertRedirect('/notes');

        $this->assertSame(2, Note::query()->where('user_id', $user->id)->count());
        $text = Note::query()->where('user_id', $user->id)->where('type', 'text')->first();
        $this->assertNotNull($text);
        $this->assertSame('買い物', $text->title);
        $this->assertSame('牛乳を買う', $text->body);
        $this->assertTrue($text->pinned);

        $list = Note::query()->where('user_id', $user->id)->where('type', 'checklist')->first();
        $this->assertNotNull($list);
        $this->assertSame('やること', $list->title);
        $this->assertTrue($list->completed);
        $this->assertCount(2, $list->items ?? []);
    }

    public function test_notes_index_shows_csv_panel(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->get('/notes')
            ->assertOk()
            ->assertSee('notes-csv-panel', false)
            ->assertSee('/notes/export', false)
            ->assertSee('/notes/import', false);
    }
}
