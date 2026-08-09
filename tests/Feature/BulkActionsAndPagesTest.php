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
            ->post('/photos/bulk/delete', [
                'ids' => [$photoDelete->id],
                'returnTo' => '/photos',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('photos', ['id' => $photoDelete->id]);
        $this->assertDatabaseHas('photos', ['id' => $photoKeep->id]);
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

    public function test_photos_bulk_routes_forbid_guest(): void
    {
        $this->post('/photos/bulk/delete', ['ids' => [1]])->assertRedirect('/login');
        $this->post('/photos/bulk/move', ['ids' => [1], 'album_id' => 1])->assertRedirect('/login');
        $this->post('/photos/bulk/archive', ['ids' => [1]])->assertRedirect('/login');
        $this->post('/photos/bulk/restore', ['ids' => [1]])->assertRedirect('/login');
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
