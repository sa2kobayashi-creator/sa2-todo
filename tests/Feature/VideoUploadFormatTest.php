<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Photo;
use App\Models\User;
use App\Services\PhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoUploadFormatTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email): User
    {
        return User::create([
            'email' => $email,
            'display_name' => 'Videographer',
            'password' => Hash::make('password123'),
            'role' => UserRole::Standard,
        ]);
    }

    private function useFakeDisk(): void
    {
        config(['photos.disk' => 'public']);
        Storage::fake('public');
    }

    public function test_mov_upload_is_stored_as_quicktime(): void
    {
        $this->useFakeDisk();
        $user = $this->makeUser('mov-upload@example.com');

        $this->actingAs($user)->post('/photos', [
            'photos' => [UploadedFile::fake()->create('clip.mov', 64, 'video/quicktime')],
        ])->assertRedirect();

        $photo = Photo::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('video/quicktime', $photo->mime);
        $this->assertStringEndsWith('.mov', $photo->path);
    }

    public function test_mp4_upload_still_stored_as_mp4(): void
    {
        $this->useFakeDisk();
        $user = $this->makeUser('mp4-upload@example.com');

        $this->actingAs($user)->post('/photos', [
            'photos' => [UploadedFile::fake()->create('clip.mp4', 64, 'video/mp4')],
        ])->assertRedirect();

        $photo = Photo::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('video/mp4', $photo->mime);
        $this->assertStringEndsWith('.mp4', $photo->path);
    }

    public function test_mov_without_a_usable_mime_is_still_recognised_as_video(): void
    {
        $service = app(PhotoService::class);

        $this->assertTrue($service->isVideoMime('application/octet-stream', 'mov'));
        $this->assertTrue($service->isVideoMime('video/quicktime', null));
        $this->assertFalse($service->isVideoMime('image/jpeg', 'jpg'));
    }

    public function test_avi_upload_keeps_its_own_container(): void
    {
        $this->useFakeDisk();
        $user = $this->makeUser('avi-upload@example.com');

        $this->actingAs($user)->post('/photos', [
            'photos' => [UploadedFile::fake()->create('clip.avi', 64, 'video/x-msvideo')],
        ])->assertRedirect();

        $photo = Photo::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('video/x-msvideo', $photo->mime);
        $this->assertStringEndsWith('.avi', $photo->path);
    }

    public function test_avi_without_a_usable_mime_is_still_recognised_as_video(): void
    {
        $service = app(PhotoService::class);

        $this->assertTrue($service->isVideoMime('application/octet-stream', 'avi'));
        $this->assertTrue($service->isVideoMime('video/x-msvideo', null));
        $this->assertTrue($service->isVideoMime('video/avi', null));
    }

    public function test_an_avi_counts_as_a_video_in_the_storage_summary(): void
    {
        $user = $this->makeUser('avi-count@example.com');
        Photo::create([
            'user_id' => $user->id,
            'path' => "photos/{$user->id}/clip.avi",
            'mime' => 'video/x-msvideo',
            'size_bytes' => 2048,
            'storage_tier' => 'hot',
            'taken_at' => '2026-07-30 10:00:00',
        ]);

        $stats = app(PhotoService::class)->storageStats((int) $user->id);

        $this->assertSame(1, $stats['videoCount']);
        $this->assertSame(0, $stats['imageCount']);
    }

    public function test_a_container_we_cannot_store_is_still_refused(): void
    {
        $this->useFakeDisk();
        $user = $this->makeUser('mkv-upload@example.com');

        $this->actingAs($user)->post('/photos', [
            'photos' => [UploadedFile::fake()->create('clip.mkv', 64, 'video/x-matroska')],
        ])->assertRedirect();

        $this->assertSame(0, Photo::query()->where('user_id', $user->id)->count());
    }

    public function test_photos_page_states_the_supported_formats_and_size_limit(): void
    {
        $user = $this->makeUser('mov-copy@example.com');

        $this->actingAs($user)->get('/photos')
            ->assertOk()
            ->assertSee('MP4・MOV・AVI（最大 1 GB）')
            // 再生できない形式だと分かるようにしておく
            ->assertSee('AVI はブラウザで再生できないため、保存とダウンロード用です。')
            ->assertSee('id="photos-lightbox-unplayable"', false);
    }

    public function test_video_up_to_one_gigabyte_is_accepted(): void
    {
        $this->useFakeDisk();
        $user = $this->makeUser('big-video@example.com');

        $this->assertSame(1073741824, app(PhotoService::class)->maxVideoUploadBytes());

        $this->actingAs($user)->post('/photos', [
            'photos' => [UploadedFile::fake()->create('big.mov', 900 * 1024, 'video/quicktime')],
        ])->assertRedirect();

        $this->assertSame(1, Photo::query()->where('user_id', $user->id)->count());
    }

    public function test_video_over_the_limit_is_refused(): void
    {
        $this->useFakeDisk();
        config(['photos.max_video_upload_bytes' => 1024 * 1024]);
        $user = $this->makeUser('too-big-video@example.com');

        $this->actingAs($user)->post('/photos', [
            'photos' => [UploadedFile::fake()->create('huge.mp4', 4 * 1024, 'video/mp4')],
        ])->assertRedirect();

        $this->assertSame(0, Photo::query()->where('user_id', $user->id)->count());
    }
}
