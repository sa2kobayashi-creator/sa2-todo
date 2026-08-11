<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\FinanceAccount;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinanceIsolationAndPhotoFileTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email): User
    {
        return User::create([
            'email' => $email,
            'display_name' => $email,
            'password' => Hash::make('password'),
            'role' => UserRole::Standard,
        ]);
    }

    public function test_account_master_is_isolated_per_user(): void
    {
        $a = $this->makeUser('finance-a@example.com');
        $b = $this->makeUser('finance-b@example.com');

        FinanceAccount::create([
            'user_id' => $a->id,
            'slug' => 'a_bank',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => 'A専用銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        FinanceAccount::create([
            'user_id' => $b->id,
            'slug' => 'b_bank',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => 'B専用銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $exportA = $this->actingAs($a)->get('/finance/export?format=accounts');
        $exportA->assertOk();
        $csvA = $exportA->streamedContent();
        $this->assertStringContainsString('A専用銀行', $csvA);
        $this->assertStringNotContainsString('B専用銀行', $csvA);

        $exportB = $this->actingAs($b)->get('/finance/export?format=accounts');
        $exportB->assertOk();
        $csvB = $exportB->streamedContent();
        $this->assertStringContainsString('B専用銀行', $csvB);
        $this->assertStringNotContainsString('A専用銀行', $csvB);

        $this->actingAs($b)->get('/finance')->assertOk()->assertDontSee('A専用銀行', false);
    }

    public function test_photo_file_endpoint_serves_owned_image(): void
    {
        config(['photos.disk' => 'public']);
        Storage::fake('public');
        $user = $this->makeUser('photo-file@example.com');
        Storage::disk('public')->put('photos/1/test.jpg', 'fake-image-bytes');

        $photo = Photo::create([
            'user_id' => $user->id,
            'album_id' => null,
            'path' => 'photos/1/test.jpg',
            'thumb_path' => null,
            'original_name' => 'test.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 16,
            'sort_order' => 0,
        ]);

        $this->actingAs($user)
            ->get('/photos/'.$photo->id.'/file')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Accept-Ranges', 'bytes');
    }

    public function test_photo_file_endpoint_supports_http_range_for_video(): void
    {
        config(['photos.disk' => 'public']);
        Storage::fake('public');
        $user = $this->makeUser('photo-range@example.com');
        $bytes = 'ABCDEFGHIJKLMNOP'; // 16 bytes
        Storage::disk('public')->put('photos/1/clip.mp4', $bytes);

        $photo = Photo::create([
            'user_id' => $user->id,
            'album_id' => null,
            'path' => 'photos/1/clip.mp4',
            'thumb_path' => null,
            'original_name' => 'clip.mp4',
            'mime' => 'video/mp4',
            'size_bytes' => strlen($bytes),
            'sort_order' => 0,
        ]);

        $full = $this->actingAs($user)->get('/photos/'.$photo->id.'/file');
        $full->assertOk()
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Type', 'video/mp4');
        $this->assertSame($bytes, $full->streamedContent());

        $partial = $this->actingAs($user)
            ->withHeaders(['Range' => 'bytes=4-7'])
            ->get('/photos/'.$photo->id.'/file');
        $partial->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 4-7/16')
            ->assertHeader('Accept-Ranges', 'bytes');
        $this->assertSame('EFGH', $partial->streamedContent());
    }

    public function test_photo_file_endpoint_rejects_unsatisfiable_range(): void
    {
        config(['photos.disk' => 'public']);
        Storage::fake('public');
        $user = $this->makeUser('photo-range-bad@example.com');
        Storage::disk('public')->put('photos/1/clip.mp4', '0123456789');

        $photo = Photo::create([
            'user_id' => $user->id,
            'album_id' => null,
            'path' => 'photos/1/clip.mp4',
            'thumb_path' => null,
            'original_name' => 'clip.mp4',
            'mime' => 'video/mp4',
            'size_bytes' => 10,
            'sort_order' => 0,
        ]);

        $this->actingAs($user)
            ->withHeaders(['Range' => 'bytes=99-120'])
            ->get('/photos/'.$photo->id.'/file')
            ->assertStatus(416)
            ->assertHeader('Content-Range', 'bytes */10');
    }

    public function test_video_file_falls_back_to_stream_on_local_disk_even_when_signed_redirect_enabled(): void
    {
        config([
            'photos.disk' => 'public',
            'photos.video_signed_redirect' => true,
        ]);
        Storage::fake('public');
        $user = $this->makeUser('photo-signed-fallback@example.com');
        Storage::disk('public')->put('photos/1/clip.mp4', '0123456789abcdef');

        $photo = Photo::create([
            'user_id' => $user->id,
            'album_id' => null,
            'path' => 'photos/1/clip.mp4',
            'thumb_path' => null,
            'original_name' => 'clip.mp4',
            'mime' => 'video/mp4',
            'size_bytes' => 16,
            'sort_order' => 0,
        ]);

        $this->actingAs($user)
            ->get('/photos/'.$photo->id.'/file')
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp4');
    }

    public function test_video_file_redirects_to_signed_url_when_available(): void
    {
        config(['photos.video_signed_redirect' => true]);
        Storage::fake('public');
        $user = $this->makeUser('photo-signed-redirect@example.com');

        $photo = Photo::create([
            'user_id' => $user->id,
            'album_id' => null,
            'path' => 'photos/1/clip.mp4',
            'thumb_path' => null,
            'original_name' => 'clip.mp4',
            'mime' => 'video/mp4',
            'size_bytes' => 16,
            'sort_order' => 0,
        ]);

        $real = app(\App\Services\PhotoService::class);
        $mock = \Mockery::mock($real)->makePartial();
        $mock->shouldReceive('findViewablePhoto')
            ->once()
            ->andReturn($photo);
        $mock->shouldReceive('temporaryVideoPlayUrl')
            ->once()
            ->with(\Mockery::on(fn ($p) => (int) $p->id === (int) $photo->id))
            ->andReturn('https://cdn.example.test/signed-clip.mp4');
        $this->app->instance(\App\Services\PhotoService::class, $mock);

        $this->actingAs($user)
            ->get('/photos/'.$photo->id.'/file')
            ->assertRedirect('https://cdn.example.test/signed-clip.mp4');
    }

    public function test_video_file_can_force_proxy_stream_even_when_signed_url_exists(): void
    {
        config([
            'photos.disk' => 'public',
            'photos.video_signed_redirect' => true,
        ]);
        Storage::fake('public');
        $user = $this->makeUser('photo-force-proxy@example.com');
        Storage::disk('public')->put('photos/1/clip.mp4', 'force-proxy-bytes');

        $photo = Photo::create([
            'user_id' => $user->id,
            'album_id' => null,
            'path' => 'photos/1/clip.mp4',
            'thumb_path' => null,
            'original_name' => 'clip.mp4',
            'mime' => 'video/mp4',
            'size_bytes' => 16,
            'sort_order' => 0,
        ]);

        $real = app(\App\Services\PhotoService::class);
        $mock = \Mockery::mock($real)->makePartial();
        $mock->shouldReceive('findViewablePhoto')->andReturn($photo);
        $mock->shouldReceive('temporaryVideoPlayUrl')->never();
        $this->app->instance(\App\Services\PhotoService::class, $mock);

        $this->actingAs($user)
            ->get('/photos/'.$photo->id.'/file?proxy=1')
            ->assertOk();
    }
}
