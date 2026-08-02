<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Photo;
use App\Models\User;
use App\Services\MediaStorageConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoR2CapDirectB2UploadTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1024 * 1024 * 1024;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'photos.disk' => 'public',
            'photos.user_quota_bytes' => 10 * self::GB,
            'photos.upload_sync_archive_limit' => 0,
        ]);
        Storage::fake('public');
        Storage::fake('backblaze');
    }

    private function useR2CapMode(): void
    {
        $config = \Mockery::mock(MediaStorageConfigService::class)->makePartial();
        $config->shouldReceive('pipelineArchivesToBackblaze')->andReturnTrue();
        $config->shouldReceive('backblazeEnabled')->andReturnTrue();
        $config->shouldReceive('capacityMode')->andReturn(MediaStorageConfigService::CAPACITY_MODE_R2_CAP);
        $config->shouldReceive('applyRuntimeDisks')->andReturnNull();
        $config->shouldReceive('recordB2Usage')->andReturnNull();
        $config->shouldReceive('pipelineUsesCloudinaryDisplay')->andReturnFalse();

        $this->app->instance(MediaStorageConfigService::class, $config);
    }

    private function makeUser(string $email): User
    {
        return User::create([
            'email' => $email,
            'display_name' => 'Cap Uploader',
            'password' => Hash::make('password123'),
            'role' => UserRole::Standard,
        ]);
    }

    public function test_r2_cap_mode_stores_new_original_on_b2_when_hot_is_over_quota(): void
    {
        $this->useR2CapMode();
        $user = $this->makeUser('over-cap@example.com');

        // ホット原本だけで 10GB 超（サムネ概算も乗る想定）
        Photo::create([
            'user_id' => $user->id,
            'path' => 'photos/'.$user->id.'/old.jpg',
            'thumb_path' => 'photos/'.$user->id.'/old_thumb.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 10 * self::GB + 1,
            'storage_tier' => 'hot',
        ]);
        Storage::disk('public')->put('photos/'.$user->id.'/old.jpg', 'old');
        Storage::disk('public')->put('photos/'.$user->id.'/old_thumb.jpg', 'thumb');

        $this->actingAs($user)->post('/photos', [
            'photos' => [UploadedFile::fake()->image('fresh.jpg', 80, 80)],
            'allow_duplicates' => 1,
        ])->assertRedirect();

        $fresh = Photo::query()
            ->where('user_id', $user->id)
            ->where('original_name', 'fresh.jpg')
            ->firstOrFail();

        $this->assertSame('cold', $fresh->storage_tier);
        $this->assertSame('backblaze', $fresh->cold_disk);
        $this->assertSame($fresh->path, $fresh->cold_path);
        $this->assertTrue(Storage::disk('backblaze')->exists($fresh->path));
        $this->assertNotEmpty($fresh->thumb_path);
        $this->assertTrue(Storage::disk('public')->exists($fresh->thumb_path));
        $this->assertFalse(Storage::disk('backblaze')->exists($fresh->thumb_path));
    }

    public function test_r2_cap_mode_keeps_original_on_primary_when_under_quota(): void
    {
        $this->useR2CapMode();
        $user = $this->makeUser('under-cap@example.com');

        $this->actingAs($user)->post('/photos', [
            'photos' => [UploadedFile::fake()->image('small.jpg', 40, 40)],
            'allow_duplicates' => 1,
        ])->assertRedirect();

        $photo = Photo::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('hot', $photo->storage_tier);
        $this->assertNull($photo->cold_disk);
        $this->assertTrue(Storage::disk('public')->exists($photo->path));
        $this->assertFalse(Storage::disk('backblaze')->exists($photo->path));
    }
}
