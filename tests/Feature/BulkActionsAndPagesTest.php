<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\Photo;
use App\Models\PhotoAlbum;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BulkActionsAndPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'email' => 'bulk@example.com',
            'display_name' => 'Bulk Tester',
            'password' => Hash::make('password'),
            'role' => 'standard',
        ]);
    }

    public function test_guest_is_redirected_from_protected_pages(): void
    {
        foreach (['/dashboard', '/todos', '/notes', '/finance', '/photos'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
    }

    public function test_authenticated_pages_render_without_server_error(): void
    {
        foreach (['/dashboard', '/todos', '/notes', '/finance', '/photos', '/transit', '/map', '/mypage'] as $uri) {
            $response = $this->actingAs($this->user)->get($uri);
            $response->assertOk();
            $this->assertStringNotContainsString('ParseError', $response->getContent());
            $this->assertStringNotContainsString('syntax error', $response->getContent());
        }

        $this->actingAs($this->user)->get('/settings')->assertForbidden();
    }

    public function test_todo_bulk_complete_updates_all_selected_ids(): void
    {
        $todos = collect(range(1, 3))->map(fn ($i) => Todo::create([
            'user_id' => $this->user->id,
            'title' => "Todo {$i}",
            'completed' => false,
            'start_date' => '2026-07-19',
            'end_date' => '2026-07-19',
            'importance' => 'medium',
            'category' => 'task',
        ]));

        $response = $this->actingAs($this->user)->post('/todos/bulk/complete', [
            'ids' => $todos->pluck('id')->all(),
            'returnTo' => '/todos',
        ]);

        $response->assertRedirect();
        foreach ($todos as $todo) {
            $this->assertTrue((bool) $todo->fresh()->completed, "Todo {$todo->id} should be completed");
        }
    }

    public function test_todo_bulk_uncomplete_updates_all_selected_ids(): void
    {
        $todos = collect(range(1, 2))->map(fn ($i) => Todo::create([
            'user_id' => $this->user->id,
            'title' => "Done {$i}",
            'completed' => true,
            'start_date' => '2026-07-19',
            'end_date' => '2026-07-19',
            'importance' => 'medium',
            'category' => 'task',
        ]));

        $response = $this->actingAs($this->user)->post('/todos/bulk/uncomplete', [
            'ids' => $todos->pluck('id')->all(),
            'returnTo' => '/todos',
        ]);

        $response->assertRedirect();
        foreach ($todos as $todo) {
            $this->assertFalse((bool) $todo->fresh()->completed);
        }
    }

    public function test_notes_bulk_archive_archives_all_selected_notes(): void
    {
        $notes = collect(range(1, 3))->map(fn ($i) => Note::create([
            'title' => "Note {$i}",
            'body' => "Body {$i}",
            'archived' => false,
            'type' => 'memo',
            'category' => 'personal',
        ]));

        $this->actingAs($this->user)
            ->from('/notes')
            ->post('/notes/bulk/archive', [
                'ids' => $notes->pluck('id')->all(),
                'returnTo' => '/notes',
            ])
            ->assertRedirect();

        foreach ($notes as $note) {
            $this->assertTrue((bool) $note->fresh()->archived);
        }
    }

    public function test_photos_page_includes_mode_controls(): void
    {
        Photo::create([
            'user_id' => $this->user->id,
            'album_id' => null,
            'path' => 'photos/sample.jpg',
            'thumb_path' => 'photos/sample-thumb.jpg',
            'original_name' => 'sample.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 100,
            'width' => 10,
            'height' => 10,
            'sort_order' => 1,
            'taken_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->get('/photos');
        $response->assertOk();
        $response->assertSee('data-photos-mode="normal"', false);
        $response->assertSee('data-photos-mode="select"', false);
        $response->assertSee('data-photos-mode="list"', false);
        $response->assertSee('photos-lightbox', false);
        $response->assertSee('photos-zoom-in', false);
    }

    public function test_photos_bulk_delete_and_move(): void
    {
        $albumA = PhotoAlbum::create([
            'user_id' => $this->user->id,
            'name' => 'Album A',
            'sort_order' => 1,
        ]);
        $albumB = PhotoAlbum::create([
            'user_id' => $this->user->id,
            'name' => 'Album B',
            'sort_order' => 2,
        ]);

        $photoKeep = Photo::create([
            'user_id' => $this->user->id,
            'album_id' => $albumA->id,
            'path' => 'photos/keep.jpg',
            'thumb_path' => 'photos/keep-thumb.jpg',
            'original_name' => 'keep.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 100,
            'width' => 10,
            'height' => 10,
            'sort_order' => 1,
        ]);
        $photoDelete = Photo::create([
            'user_id' => $this->user->id,
            'album_id' => $albumA->id,
            'path' => 'photos/del.jpg',
            'thumb_path' => 'photos/del-thumb.jpg',
            'original_name' => 'del.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 100,
            'width' => 10,
            'height' => 10,
            'sort_order' => 2,
        ]);
        $photoMove = Photo::create([
            'user_id' => $this->user->id,
            'album_id' => $albumA->id,
            'path' => 'photos/move.jpg',
            'thumb_path' => 'photos/move-thumb.jpg',
            'original_name' => 'move.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 100,
            'width' => 10,
            'height' => 10,
            'sort_order' => 3,
        ]);

        $this->actingAs($this->user)
            ->post('/photos/bulk/move', [
                'ids' => [$photoMove->id],
                'album_id' => $albumB->id,
                'returnTo' => '/photos',
            ])
            ->assertRedirect();

        $this->assertSame($albumB->id, (int) $photoMove->fresh()->album_id);

        $this->actingAs($this->user)
            ->post('/photos/bulk/delete', [
                'ids' => [$photoDelete->id],
                'returnTo' => '/photos',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('photos', ['id' => $photoDelete->id]);
        $this->assertDatabaseHas('photos', ['id' => $photoKeep->id]);
    }

    public function test_photos_bulk_routes_forbid_guest(): void
    {
        $this->post('/photos/bulk/delete', ['ids' => [1]])->assertRedirect('/login');
        $this->post('/photos/bulk/move', ['ids' => [1], 'album_id' => 1])->assertRedirect('/login');
    }
}
