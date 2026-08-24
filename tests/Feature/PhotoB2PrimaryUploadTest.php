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

class PhotoB2PrimaryUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'photos.disk' => 'public',
            'photos.upload_sync_archive_limit' => 0,
        ]);
        Storage::fake('public');
        Storage::fake('backblaze');
    }

    private function useB2PrimaryMode(bool $b2Enabled = true): void
    {
        $config = \Mockery::mock(MediaStorageConfigService::class)->makePartial();
        $config->shouldReceive('pipelineArchivesToBackblaze')->andReturn($b2Enabled);
        $config->shouldReceive('backblazeEnabled')->andReturn($b2Enabled);
        $config->shouldReceive('capacityMode')->andReturn(MediaStorageConfigService::CAPACITY_MODE_B2_PRIMARY);
        $config->shouldReceive('applyRuntimeDisks')->andReturnNull();
        $config->shouldReceive('recordB2Usage')->andReturnNull();
        $config->shouldReceive('pipelineUsesCloudinaryDisplay')->andReturnFalse();

        $this->app->instance(MediaStorageConfigService::class, $config);
    }

    private function makeUser(string $email): User
    {
        return User::create([
            'email' => $email,
            'display_name' => 'B2 Uploader',
            'password' => Hash::make('password123'),
            'role' => UserRole::Standard,
        ]);
    }

    public function test_b2_primary_stores_new_original_on_b2_and_thumb_on_primary(): void
    {
        $this->useB2PrimaryMode();
        $user = $this->makeUser('b2-primary@example.com');

        $this->actingAs($user)->post('/photos', [
            'photos' => [UploadedFile::fake()->image('fresh.jpg', 80, 80)],
            'allow_duplicates' => 1,
        ])->assertRedirect();

        $photo = Photo::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame('cold', $photo->storage_tier);
        $this->assertSame('backblaze', $photo->cold_disk);
        $this->assertSame($photo->path, $photo->cold_path);
        $this->assertTrue(Storage::disk('backblaze')->exists($photo->path));
        $this->assertFalse(Storage::disk('public')->exists($photo->path));
        $this->assertNotEmpty($photo->thumb_path);
        $this->assertTrue(Storage::disk('public')->exists($photo->thumb_path));
        $this->assertFalse(Storage::disk('backblaze')->exists($photo->thumb_path));
    }

    public function test_b2_primary_falls_back_to_primary_when_b2_is_disabled(): void
    {
        $this->useB2PrimaryMode(false);
        $user = $this->makeUser('b2-off@example.com');

        $this->actingAs($user)->post('/photos', [
            'photos' => [UploadedFile::fake()->image('local.jpg', 40, 40)],
            'allow_duplicates' => 1,
        ])->assertRedirect();

        $photo = Photo::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('hot', $photo->storage_tier);
        $this->assertNull($photo->cold_disk);
        $this->assertTrue(Storage::disk('public')->exists($photo->path));
        $this->assertFalse(Storage::disk('backblaze')->exists($photo->path));
    }
}
