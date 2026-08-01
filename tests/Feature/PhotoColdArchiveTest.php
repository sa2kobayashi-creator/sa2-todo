<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Photo;
use App\Models\User;
use App\Services\MediaStorageConfigService;
use App\Services\PhotoColdArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        config(['photos.disk' => 'public', 'photos.user_quota_bytes' => 10 * self::GB]);
        Storage::fake('public');
        Storage::fake('backblaze');
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
}
