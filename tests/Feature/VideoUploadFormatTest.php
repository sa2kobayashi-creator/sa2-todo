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

    public function test_unsupported_video_container_is_refused(): void
    {
        $this->useFakeDisk();
        $user = $this->makeUser('avi-upload@example.com');

        $this->actingAs($user)->post('/photos', [
            'photos' => [UploadedFile::fake()->create('clip.avi', 64, 'video/x-msvideo')],
        ])->assertRedirect();

        $this->assertSame(0, Photo::query()->where('user_id', $user->id)->count());
    }

    public function test_photos_page_states_the_supported_formats_and_size_limit(): void
    {
        $user = $this->makeUser('mov-copy@example.com');

        $this->actingAs($user)->get('/photos')
            ->assertOk()
            ->assertSee('MP4・MOV（最大 1 GB）');
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
