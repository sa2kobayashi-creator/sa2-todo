<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\Photo;
use App\Models\PhotoAlbum;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
            'user_id' => $this->user->id,
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
        $html = $response->getContent();

        $response->assertSee('data-photos-mode="normal"', false);
        $response->assertSee('data-photos-mode="select"', false);
        $response->assertSee('data-photos-mode="list"', false);
        $response->assertSee('data-photos-mode="archive"', false);
        $response->assertSee('id="photos-bulk-archive"', false);
        $response->assertSee('id="photos-bulk-restore"', false);
        $response->assertSee('id="photos-bulk-edit"', false);
        $response->assertSee('id="photos-bulk-edit-modal"', false);
        $response->assertSee('photos-lightbox', false);
        $response->assertSee('photos-lb-detail-open', false);
        $response->assertDontSee('photos-zoom-in', false);

        // 操作UIの契約（ボタン無反応の再発防止用マーカー）
        foreach ([
            'id="photos-ops-open"',
            'id="photos-ops-panel"',
            'id="photos-ops-backdrop"',
            'id="photos-ops-close"',
            'id="photos-toolbar"',
            'id="photos-select-actions"',
            'id="photos-range-mode"',
            'id="photos-dock-select-all"',
            'id="photos-dock-select-none"',
            'id="photos-dock-range-mode"',
            'id="photos-year-dock-select-actions"',
            'id="photos-bulk-bar"',
            'function setPhotosOpsOpen',
            'function hidePhotosOpsBackdrop',
            'function showPhotosOpsBackdrop',
            'function isPhotosOpsPanelOpen',
            'function readPhotosMode',
            'function applyRangeBetweenWraps',
            'function resetRangeSelect',
            "opsBackdrop.style.pointerEvents = 'none'",
        ] as $marker) {
            $this->assertStringContainsString($marker, $html, "Missing photos UI contract: {$marker}");
        }

        // CSS: 表示中でも backdrop がクリックを奪わない
        $css = file_get_contents(public_path('app.css'));
        $this->assertNotFalse($css);
        $this->assertMatchesRegularExpression(
            '/\.photos-ops-backdrop:not\(\[hidden\]\)\s*\{[^}]*pointer-events:\s*none\s*!important/s',
            $css,
            'photos-ops-backdrop must keep pointer-events:none while visible'
        );

        // Blade @json の直後改行が PHP に消されると構文エラーになるのでセミコロン付き割り当てを維持
        $this->assertStringContainsString('function syncRangeModeButtons', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/"[^"]*"\s+if\s*\(/',
            $html,
            'Missing semicolon after @json before next if (PHP strips newline after ?>)'
        );
        $this->assertMatchesRegularExpression(
            '/active\s*\?\s*"[^"]*"\s*:\s*"[^"]*";/',
            $html
        );

        // モバイル選択バーが下ナビに隠れないこと（CSS 契約）
        $this->assertStringContainsString(
            '/* .mobile-bottom-nav と同じ高さ帯の上。bottom:0 だと全選択/範囲選択がナビ裏に隠れる */',
            $css
        );
        $this->assertStringContainsString(
            'bottom: calc(64px + env(safe-area-inset-bottom, 0px));',
            $css
        );
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
            ->postJson('/photos/bulk/move', [
                'ids' => [$photoMove->id],
                'album_id' => null,
            ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'count' => 1,
            ]);

        $this->assertNull($photoMove->fresh()->album_id);

        $this->actingAs($this->user)
            ->post('/photos/bulk/delete', [
                'ids' => [$photoDelete->id],
                'returnTo' => '/photos',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('photos', ['id' => $photoDelete->id]);
        $this->assertDatabaseHas('photos', ['id' => $photoKeep->id]);
    }

    public function test_photos_bulk_update_changes_taken_at_and_dashboard_visibility(): void
    {
        $photo = Photo::create([
            'user_id' => $this->user->id,
            'path' => 'photos/bulk-edit.jpg',
            'original_name' => 'bulk-edit.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 100,
            'taken_at' => '2024-01-01 12:00:00',
            'show_on_dashboard' => true,
        ]);

        $this->actingAs($this->user)
            ->postJson('/photos/bulk/update', [
                'ids' => [$photo->id],
                'taken_at' => '2026-08-12T15:30',
                'show_on_dashboard' => '0',
            ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'count' => 1,
            ]);

        $fresh = $photo->fresh();
        $this->assertFalse((bool) $fresh->show_on_dashboard);
        $this->assertSame('2026-08-12 15:30:00', $fresh->taken_at?->format('Y-m-d H:i:s'));
    }

    public function test_photos_root_scope_hides_album_photos_by_default(): void
    {
        $album = PhotoAlbum::create([
            'user_id' => $this->user->id,
            'name' => 'Scoped Album',
            'sort_order' => 1,
        ]);

        Photo::create([
            'user_id' => $this->user->id,
            'album_id' => null,
            'path' => 'photos/loose-only.jpg',
            'thumb_path' => 'photos/loose-only-thumb.jpg',
            'original_name' => 'loose-only.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 100,
            'width' => 10,
            'height' => 10,
            'sort_order' => 1,
            'taken_at' => now(),
        ]);
        Photo::create([
            'user_id' => $this->user->id,
            'album_id' => $album->id,
            'path' => 'photos/in-album.jpg',
            'thumb_path' => 'photos/in-album-thumb.jpg',
            'original_name' => 'in-album.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 100,
            'width' => 10,
            'height' => 10,
            'sort_order' => 2,
            'taken_at' => now(),
        ]);

        $loose = $this->actingAs($this->user)->get('/photos');
        $loose->assertOk();
        $loose->assertSee('loose-only.jpg', false);
        $this->assertStringNotContainsString('in-album.jpg', $loose->getContent());
        $loose->assertSee('id="photos-scope-select"', false);
        $loose->assertSee('アルバム以外', false);

        $library = $this->actingAs($this->user)->get('/photos?scope=library');
        $library->assertOk();
        $library->assertSee('loose-only.jpg', false);
        $library->assertSee('in-album.jpg', false);
        $library->assertSee('value="library"', false);
        $library->assertSee(__('アルバム含む'), false);
    }

    public function test_photos_library_scope_excludes_hidden_album_photos(): void
    {
        $visibleAlbum = PhotoAlbum::create([
            'user_id' => $this->user->id,
            'name' => 'Visible Album',
            'sort_order' => 1,
            'is_hidden' => false,
        ]);
        $hiddenAlbum = PhotoAlbum::create([
            'user_id' => $this->user->id,
            'name' => 'Hidden Album',
            'sort_order' => 2,
            'is_hidden' => true,
        ]);

        Photo::create([
            'user_id' => $this->user->id,
            'album_id' => $visibleAlbum->id,
            'path' => 'photos/visible-album.jpg',
            'thumb_path' => 'photos/visible-album-thumb.jpg',
            'original_name' => 'visible-album.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 100,
            'width' => 10,
            'height' => 10,
            'sort_order' => 1,
            'taken_at' => now(),
        ]);
        Photo::create([
            'user_id' => $this->user->id,
            'album_id' => $hiddenAlbum->id,
            'path' => 'photos/secret-album.jpg',
            'thumb_path' => 'photos/secret-album-thumb.jpg',
            'original_name' => 'secret-album.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 100,
            'width' => 10,
            'height' => 10,
            'sort_order' => 2,
            'taken_at' => now(),
        ]);

        $library = $this->actingAs($this->user)->get('/photos?scope=library');
        $library->assertOk();
        $library->assertSee('visible-album.jpg', false);
        $this->assertStringNotContainsString('secret-album.jpg', $library->getContent());
    }

    public function test_photos_bulk_archive_and_restore(): void
    {
        $photo = Photo::create([
            'user_id' => $this->user->id,
            'album_id' => null,
            'path' => 'photos/archive-me.jpg',
            'thumb_path' => 'photos/archive-me-thumb.jpg',
            'original_name' => 'archive-me.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 100,
            'width' => 10,
            'height' => 10,
            'sort_order' => 1,
            'taken_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->post('/photos/bulk/archive', [
                'ids' => [$photo->id],
                'returnTo' => '/photos',
            ])
            ->assertRedirect();

        $this->assertNotNull($photo->fresh()->archived_at);

        $active = $this->actingAs($this->user)->get('/photos');
        $active->assertOk();
        $this->assertStringNotContainsString('archive-me.jpg', $active->getContent());

        $archived = $this->actingAs($this->user)->get('/photos?library=archived');
        $archived->assertOk();
        $archived->assertSee('archive-me.jpg', false);
        $archived->assertSee('data-photos-library="archived"', false);

        $this->actingAs($this->user)
            ->post('/photos/bulk/restore', [
                'ids' => [$photo->id],
                'returnTo' => '/photos?library=archived',
            ])
            ->assertRedirect();

        $this->assertNull($photo->fresh()->archived_at);
    }

    public function test_archived_photos_can_be_bulk_deleted(): void
    {
        $photo = Photo::create([
            'user_id' => $this->user->id,
            'album_id' => null,
            'path' => 'photos/delete-archived.jpg',
            'thumb_path' => 'photos/delete-archived-thumb.jpg',
            'original_name' => 'delete-archived.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 100,
            'width' => 10,
            'height' => 10,
            'sort_order' => 1,
            'taken_at' => now(),
            'archived_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->from('/photos?library=archived')
            ->post('/photos/bulk/delete', [
                'ids' => [$photo->id],
                'returnTo' => '/photos?library=archived',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('photos', ['id' => $photo->id]);
    }

    public function test_bulk_delete_json_processes_in_chunks_of_one_hundred(): void
    {
        $ids = [];
        for ($i = 0; $i < 105; $i++) {
            $photo = Photo::create([
                'user_id' => $this->user->id,
                'album_id' => null,
                'path' => "photos/chunk-{$i}.jpg",
                'thumb_path' => "photos/chunk-{$i}-thumb.jpg",
                'original_name' => "chunk-{$i}.jpg",
                'mime' => 'image/jpeg',
                'size_bytes' => 100,
                'width' => 10,
                'height' => 10,
                'sort_order' => $i,
                'taken_at' => now(),
            ]);
            $ids[] = $photo->id;
        }

        $this->actingAs($this->user)
            ->postJson('/photos/bulk/delete', ['ids' => $ids])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'count' => 100,
                'requested' => 100,
                'remaining' => 5,
                'deferred_cleanup' => true,
            ]);

        $this->assertSame(5, Photo::query()->where('user_id', $this->user->id)->count());
    }

    public function test_archived_photo_can_be_deleted_individually(): void
    {
        $photo = Photo::create([
            'user_id' => $this->user->id,
            'album_id' => null,
            'path' => 'photos/delete-one-archived.jpg',
            'thumb_path' => 'photos/delete-one-archived-thumb.jpg',
            'original_name' => 'delete-one-archived.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 100,
            'width' => 10,
            'height' => 10,
            'sort_order' => 1,
            'taken_at' => now(),
            'archived_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->post('/photos/'.$photo->id.'/delete', [
                'returnTo' => '/photos?library=archived',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('photos', ['id' => $photo->id]);
    }

    public function test_photos_bulk_restore_clears_album_membership(): void
    {
        $album = PhotoAlbum::create([
            'user_id' => $this->user->id,
            'name' => '元アルバム',
            'visibility' => 'private',
        ]);
        $photo = Photo::create([
            'user_id' => $this->user->id,
            'album_id' => $album->id,
            'path' => 'photos/restore-to-all.jpg',
            'thumb_path' => 'photos/restore-to-all-thumb.jpg',
            'original_name' => 'restore-to-all.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 100,
            'width' => 10,
            'height' => 10,
            'sort_order' => 1,
            'taken_at' => now(),
            'archived_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->post('/photos/bulk/restore', [
                'ids' => [$photo->id],
                'returnTo' => '/photos?library=archived',
            ])
            ->assertRedirect();

        $fresh = $photo->fresh();
        $this->assertNull($fresh->archived_at);
        $this->assertNull($fresh->album_id);
    }

    public function test_photos_bulk_routes_forbid_guest(): void
    {
        $this->post('/photos/bulk/delete', ['ids' => [1]])->assertRedirect('/login');
        $this->post('/photos/bulk/move', ['ids' => [1], 'album_id' => 1])->assertRedirect('/login');
        $this->post('/photos/bulk/archive', ['ids' => [1]])->assertRedirect('/login');
        $this->post('/photos/bulk/restore', ['ids' => [1]])->assertRedirect('/login');
        $this->post('/photos/bulk/update', ['ids' => [1]])->assertRedirect('/login');
    }

    public function test_photos_upload_succeeds_when_gd_full_decode_is_skipped(): void
    {
        // 超高解像度スマホ写真は GD フルデコードを避けて保存する（OOM 防止）
        config(['photos.gd_max_source_pixels' => 1]);

        $this->actingAs($this->user)
            ->post('/photos', [
                'photos' => [UploadedFile::fake()->image('hd-phone.jpg', 640, 480)],
                'allow_duplicates' => 1,
                'returnTo' => '/photos',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('photos', [
            'user_id' => $this->user->id,
            'original_name' => 'hd-phone.jpg',
        ]);
    }
}
