<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Photo;
use App\Models\PhotoAlbum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoUploadAlbumTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email): User
    {
        return User::create([
            'email' => $email,
            'display_name' => 'Photographer',
            'password' => Hash::make('password123'),
            'role' => UserRole::Standard,
        ]);
    }

    private function makeAlbum(User $user, string $name): PhotoAlbum
    {
        return PhotoAlbum::create([
            'user_id' => $user->id,
            'name' => $name,
            'visibility' => 'private',
        ]);
    }

    public function test_add_sheet_offers_an_album_destination_with_a_default(): void
    {
        $user = $this->makeUser('picker@example.com');
        $this->makeAlbum($user, '旅行2026');

        $this->actingAs($user)->get('/photos')
            ->assertOk()
            ->assertSee('追加先アルバム')
            ->assertSee('標準（アルバムなし）')
            ->assertSee('旅行2026');
    }

    public function test_upload_without_an_album_stays_out_of_albums(): void
    {
        config(['photos.disk' => 'public']);
        Storage::fake('public');

        $user = $this->makeUser('default-dest@example.com');
        $this->makeAlbum($user, '旅行2026');

        $this->actingAs($user)->post('/photos', [
            'photos' => [UploadedFile::fake()->image('plain.jpg', 40, 40)],
        ])->assertRedirect();

        $photo = Photo::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertNull($photo->album_id);
    }

    public function test_upload_lands_in_the_chosen_album(): void
    {
        config(['photos.disk' => 'public']);
        Storage::fake('public');

        $user = $this->makeUser('chosen-dest@example.com');
        $album = $this->makeAlbum($user, '旅行2026');

        $this->actingAs($user)->post('/photos', [
            'album_id' => $album->id,
            'photos' => [UploadedFile::fake()->image('trip.jpg', 40, 40)],
        ])->assertRedirect();

        $photo = Photo::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame($album->id, $photo->album_id);
    }

    public function test_upload_to_someone_elses_album_is_refused(): void
    {
        config(['photos.disk' => 'public']);
        Storage::fake('public');

        $owner = $this->makeUser('album-owner@example.com');
        $album = $this->makeAlbum($owner, '他人のアルバム');
        $intruder = $this->makeUser('intruder@example.com');

        $this->actingAs($intruder)->post('/photos', [
            'album_id' => $album->id,
            'photos' => [UploadedFile::fake()->image('sneaky.jpg', 40, 40)],
        ])->assertRedirect();

        $this->assertSame(0, Photo::query()->where('album_id', $album->id)->count());
    }
}
