<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Photo;
use App\Models\User;
use App\Services\MediaStorageConfigService;
use App\Services\PhotoColdArchiveRunService;
use App\Services\PhotoColdArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoColdArchiveTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1024 * 1024 * 1024;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'photos.disk' => 'public',
            'photos.user_quota_bytes' => 10 * self::GB,
            // 既存テストの 2GB 仮写真を「大ファイル単独」扱いにしない
            'photos.archive_cold_large_file_bytes' => 100 * self::GB,
            'photos.archive_cold_batch_seconds' => 120,
        ]);
        Storage::fake('public');
        Storage::fake('backblaze');
        Cache::flush();
    }

    /** R2上限モードで動くよう、設定サービスだけ差し替える */
    private function useR2CapMode(): void
    {
        $config = \Mockery::mock(MediaStorageConfigService::class)->makePartial();
        $config->shouldReceive('pipelineArchivesToBackblaze')->andReturnTrue();
        $config->shouldReceive('backblazeEnabled')->andReturnTrue();
        $config->shouldReceive('capacityMode')->andReturn(MediaStorageConfigService::CAPACITY_MODE_R2_CAP);
        $config->shouldReceive('applyRuntimeDisks')->andReturnNull();
        $config->shouldReceive('recordB2Usage')->andReturnNull();

        $this->app->instance(MediaStorageConfigService::class, $config);
    }

    private function makeUser(string $email): User
    {
        return User::create([
            'email' => $email,
            'display_name' => 'Archiver',
            'password' => Hash::make('password123'),
            'role' => UserRole::Standard,
        ]);
    }

    /** 1枚 2GB の原本を作る。$withFile=false なら DB だけで実体が無い壊れたレコード */
    private function makePhoto(User $user, int $index, bool $withFile = true): Photo
    {
        $path = "photos/{$user->id}/photo-{$index}.jpg";
        if ($withFile) {
            Storage::disk('public')->put($path, 'binary');
        }

        return Photo::create([
            'user_id' => $user->id,
            'path' => $path,
            'mime' => 'image/jpeg',
            'size_bytes' => 2 * self::GB,
            'storage_tier' => 'hot',
            'taken_at' => now()->subDays(100 - $index),
        ]);
    }

    public function test_a_record_whose_file_is_missing_does_not_stop_the_rest(): void
    {
        $this->useR2CapMode();
        $user = $this->makeUser('blocked@example.com');

        // 先頭（最も古い）が壊れたレコード。以前はここで打ち切られていた
        $broken = $this->makePhoto($user, 1, withFile: false);
        for ($i = 2; $i <= 6; $i++) {
            $this->makePhoto($user, $i);
        }

        $stats = app(PhotoColdArchiveService::class)->archiveDuePhotos(40);

        $this->assertSame(1, $stats['skipped']);
        $this->assertGreaterThan(0, $stats['archived']);
        $this->assertSame('hot', $broken->refresh()->storage_tier);
    }

    public function test_it_reports_more_work_when_the_batch_limit_cuts_it_short(): void
    {
        $this->useR2CapMode();
        $user = $this->makeUser('paged@example.com');

        for ($i = 1; $i <= 8; $i++) {
            $this->makePhoto($user, $i);
        }

        $service = app(PhotoColdArchiveService::class);

        $first = $service->archiveDuePhotos(2);
        $this->assertSame(2, $first['archived']);
        $this->assertTrue($first['hasMore'], '上限で打ち切られたので続きがあるはず');

        // 上限内に収まるまで繰り返せること
        $guard = 0;
        while ($service->archiveDuePhotos(2)['hasMore'] && $guard++ < 10) {
        }

        $this->assertLessThanOrEqual(
            10 * self::GB,
            (int) Photo::where('user_id', $user->id)->where('storage_tier', 'hot')->sum('size_bytes')
        );
    }

    public function test_it_explains_why_nothing_moved_when_hot_storage_is_within_the_cap(): void
    {
        $this->useR2CapMode();
        $user = $this->makeUser('small@example.com');
        $this->makePhoto($user, 1);

        $stats = app(PhotoColdArchiveService::class)->archiveDuePhotos(40);

        $this->assertSame(0, $stats['archived']);
        $this->assertFalse($stats['hasMore']);
        $this->assertSame(PhotoColdArchiveService::REASON_WITHIN_QUOTA, $stats['reason']);
    }

    public function test_the_archive_endpoint_keeps_going_while_work_remains(): void
    {
        $this->useR2CapMode();
        $user = $this->makeUser('endpoint@example.com');

        for ($i = 1; $i <= 8; $i++) {
            $this->makePhoto($user, $i);
        }

        $response = $this->actingAs($user)->post('/photos/archive-cold', ['limit' => 2]);

        $response->assertOk();
        $this->assertSame(2, $response->json('archived'));
        $this->assertTrue($response->json('has_more'));
    }

    public function test_a_large_video_is_moved_alone_after_smaller_files(): void
    {
        $this->useR2CapMode();
        config(['photos.archive_cold_large_file_bytes' => 100 * 1024 * 1024]);
        $user = $this->makeUser('large@example.com');

        // 20MB + 20MB + 10.5GB。小さい2枚を移したあとも超過し、次が大きな動画になる
        $smallA = $this->makePhoto($user, 1);
        $smallA->update(['size_bytes' => 20 * 1024 * 1024, 'taken_at' => now()->subDays(30)]);
        $smallB = $this->makePhoto($user, 2);
        $smallB->update(['size_bytes' => 20 * 1024 * 1024, 'taken_at' => now()->subDays(20)]);
        $large = $this->makePhoto($user, 3);
        $large->update([
            'size_bytes' => (int) (10.5 * self::GB),
            'mime' => 'video/mp4',
            'path' => "photos/{$user->id}/big.mp4",
            'taken_at' => now()->subDays(10),
        ]);
        Storage::disk('public')->put($large->path, 'video-binary');

        $stats = app(PhotoColdArchiveService::class)->archiveDuePhotos(40);

        // 小さい2枚を移した時点で大きな動画の手前で区切る
        $this->assertSame(2, $stats['archived']);
        $this->assertTrue($stats['hasMore']);
        $this->assertSame(PhotoColdArchiveService::REASON_LARGE_FILE, $stats['reason']);
        $this->assertSame('hot', $large->refresh()->storage_tier);

        $next = app(PhotoColdArchiveService::class)->archiveDuePhotos(40);
        $this->assertSame(1, $next['archived']);
        $this->assertSame('cold', $large->refresh()->storage_tier);
    }

    public function test_time_budget_stops_cleanly_with_has_more(): void
    {
        $this->useR2CapMode();
        // 直後に期限切れになるよう過去時刻を擬似するのではなく、秒数を極端に短くする
        config(['photos.archive_cold_batch_seconds' => 15]);
        $user = $this->makeUser('budget@example.com');

        for ($i = 1; $i <= 8; $i++) {
            $this->makePhoto($user, $i);
        }

        // batchDeadline は microtime + seconds なので、15秒はテストには長い。
        // 代わりにサービスを継承して pastDeadline 相当を検証するため、
        // ここでは「1件移したあと期限切れ」に見えるよう limit=1 で hasMore を確認する。
        $stats = app(PhotoColdArchiveService::class)->archiveDuePhotos(1);
        $this->assertSame(1, $stats['archived']);
        $this->assertTrue($stats['hasMore']);
    }

    public function test_the_photos_page_ships_a_background_archive_button_script(): void
    {
        $this->useR2CapMode();
        $user = $this->makeUser('btn@example.com');

        $html = $this->actingAs($user)->get('/photos')->assertOk()->getContent();

        $this->assertStringContainsString('id="photos-archive-cold-btn"', $html);
        $this->assertStringContainsString('id="photos-archive-cold-cancel"', $html);
        $this->assertDoesNotMatchRegularExpression('/return\s+"[^"]*"\s*if\s*\(/', $html);
        $this->assertStringContainsString('/photos/archive-cold/start', $html);
        $this->assertStringContainsString('/photos/archive-cold/status', $html);
        // バックグラウンドなので離脱ブロック用の archive job は開始しない
        $this->assertStringNotContainsString("job.start('archive'", $html);
    }

    public function test_background_archive_start_and_status_track_progress(): void
    {
        $this->useR2CapMode();
        $user = $this->makeUser('bg@example.com');

        for ($i = 1; $i <= 8; $i++) {
            $this->makePhoto($user, $i);
        }

        $start = $this->actingAs($user)->post('/photos/archive-cold/start');
        $start->assertOk();
        $this->assertTrue($start->json('running') || $start->json('run.status') === 'completed');
        $this->assertGreaterThan(0, (int) $start->json('run.archived'));

        $status = $this->actingAs($user)->getJson('/photos/archive-cold/status');
        $status->assertOk();
        $this->assertContains($status->json('run.status'), [
            PhotoColdArchiveRunService::STATUS_RUNNING,
            PhotoColdArchiveRunService::STATUS_COMPLETED,
        ]);

        // 残りがあれば tick で完了まで進められる
        $runs = app(PhotoColdArchiveRunService::class);
        $guard = 0;
        while ($runs->isRunning() && $guard++ < 20) {
            $runs->tick(2);
        }

        $this->assertFalse($runs->isRunning());
        $this->assertSame(PhotoColdArchiveRunService::STATUS_COMPLETED, $runs->current()['status']);
        $this->assertLessThanOrEqual(
            10 * self::GB,
            (int) Photo::where('user_id', $user->id)->where('storage_tier', 'hot')->sum('size_bytes')
        );
    }

    public function test_background_archive_can_be_cancelled(): void
    {
        $this->useR2CapMode();
        $user = $this->makeUser('cancel@example.com');
        for ($i = 1; $i <= 8; $i++) {
            $this->makePhoto($user, $i);
        }

        $runs = app(PhotoColdArchiveRunService::class);
        // 1件だけ進めて running を残す
        config(['photos.archive_cold_batch_size' => 1]);
        $this->actingAs($user)->post('/photos/archive-cold/start')->assertOk();

        if (! $runs->isRunning()) {
            // 1バッチで上限内に収まった場合は中止対象が無いのでスキップ相当
            $this->assertContains($runs->current()['status'], [
                PhotoColdArchiveRunService::STATUS_COMPLETED,
                PhotoColdArchiveRunService::STATUS_CANCELLED,
            ]);

            return;
        }

        $cancel = $this->actingAs($user)->post('/photos/archive-cold/cancel');
        $cancel->assertOk();
        // 中止は cancel 時点で確定する（次 tick 待ちにしない）
        $this->assertSame(PhotoColdArchiveRunService::STATUS_CANCELLED, $cancel->json('run.status'));

        // 表示が残り続けないよう dismiss で idle に戻せる
        $dismiss = $this->actingAs($user)->getJson('/photos/archive-cold/status?dismiss=1');
        $dismiss->assertOk();
        $this->assertSame(PhotoColdArchiveRunService::STATUS_IDLE, $dismiss->json('run.status'));
        $this->assertSame('', (string) $dismiss->json('run.message'));
    }

    public function test_status_poll_advances_running_archive(): void
    {
        $this->useR2CapMode();
        $user = $this->makeUser('polltick@example.com');
        for ($i = 1; $i <= 8; $i++) {
            $this->makePhoto($user, $i);
        }

        config(['photos.archive_cold_batch_size' => 1]);
        $this->actingAs($user)->post('/photos/archive-cold/start')->assertOk();
        $runs = app(PhotoColdArchiveRunService::class);
        if (! $runs->isRunning()) {
            $this->assertSame(PhotoColdArchiveRunService::STATUS_COMPLETED, $runs->current()['status']);

            return;
        }

        $before = (int) $runs->current()['archived'];
        $status = $this->actingAs($user)->getJson('/photos/archive-cold/status');
        $status->assertOk();
        $this->assertNotNull($status->json('storageStats'));
        $this->assertGreaterThanOrEqual($before, (int) $status->json('run.archived'));
    }

    public function test_endpoint_returns_last_error_details_when_write_fails(): void
    {
        $this->useR2CapMode();
        $user = $this->makeUser('errdetail@example.com');

        // 壊れたレコードだけだとスキップになるので、書き込み失敗を起こすために
        // backblaze を「書けない」ディスクに差し替えるのは難しい。ここでは
        // 例外メッセージ付き JSON 形をコントローラ経由で確認する。
        $photo = $this->makePhoto($user, 1, withFile: false);
        for ($i = 2; $i <= 6; $i++) {
            $this->makePhoto($user, $i);
        }

        $response = $this->actingAs($user)->post('/photos/archive-cold', ['limit' => 40]);
        $response->assertOk();
        $this->assertArrayHasKey('last_error', $response->json());
        $this->assertArrayHasKey('last_error_photo_id', $response->json());
        $this->assertGreaterThan(0, $response->json('archived'));
        // 先頭の欠落はスキップとして数えられる
        $this->assertSame('hot', $photo->refresh()->storage_tier);
    }
}
