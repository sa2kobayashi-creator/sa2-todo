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

    public function test_album_with_photos_cannot_be_deleted(): void
    {
        $user = $this->makeUser('keep-photos@example.com');
        $album = $this->makeAlbum($user, '残すアルバム');
        Photo::create([
            'user_id' => $user->id,
            'album_id' => $album->id,
            'path' => "photos/{$user->id}/keep.jpg",
            'mime' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_tier' => 'hot',
            'taken_at' => '2024-06-01 10:00:00',
        ]);

        $message = '通常の写真・動画が残っているため削除できません。先に移動または削除してください（アーカイブのみなら削除できます）。';
        $this->actingAs($user)
            ->post('/photos/albums/'.$album->id.'/delete', ['returnTo' => '/photos?album='.$album->id])
            ->assertRedirect('/photos?album='.$album->id.'&error='.urlencode($message));

        $this->assertDatabaseHas('photo_albums', ['id' => $album->id]);
        $this->assertSame(1, Photo::query()->where('album_id', $album->id)->count());
    }

    public function test_album_with_only_archived_photos_can_be_deleted(): void
    {
        $user = $this->makeUser('archived-only@example.com');
        $album = $this->makeAlbum($user, 'アーカイブのみ');
        Photo::create([
            'user_id' => $user->id,
            'album_id' => $album->id,
            'path' => "photos/{$user->id}/cold.jpg",
            'mime' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_tier' => 'hot',
            'taken_at' => '2024-06-01 10:00:00',
            'archived_at' => '2024-07-01 10:00:00',
        ]);

        $this->actingAs($user)
            ->post('/photos/albums/'.$album->id.'/delete', ['returnTo' => '/photos?album='.$album->id])
            ->assertRedirect('/photos?notice='.urlencode('アルバムを削除しました'));

        $this->assertDatabaseMissing('photo_albums', ['id' => $album->id]);
        $this->assertSame(1, Photo::query()->where('user_id', $user->id)->whereNull('album_id')->whereNotNull('archived_at')->count());
    }

    public function test_empty_album_can_be_deleted(): void
    {
        $user = $this->makeUser('empty-album@example.com');
        $album = $this->makeAlbum($user, '空アルバム');

        $this->actingAs($user)
            ->post('/photos/albums/'.$album->id.'/delete', ['returnTo' => '/photos?album='.$album->id])
            ->assertRedirect('/photos?notice='.urlencode('アルバムを削除しました'));

        $this->assertDatabaseMissing('photo_albums', ['id' => $album->id]);
    }

    public function test_album_cover_can_be_chosen_while_editing(): void
    {
        config(['photos.disk' => 'public']);
        \Illuminate\Support\Facades\Storage::fake('public');

        $user = $this->makeUser('cover-edit@example.com');
        $album = $this->makeAlbum($user, '表紙編集');
        $first = Photo::create([
            'user_id' => $user->id,
            'album_id' => $album->id,
            'path' => "photos/{$user->id}/first.jpg",
            'thumb_path' => "photos/{$user->id}/first-thumb.jpg",
            'mime' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_tier' => 'hot',
            'taken_at' => '2024-06-01 10:00:00',
        ]);
        $second = Photo::create([
            'user_id' => $user->id,
            'album_id' => $album->id,
            'path' => "photos/{$user->id}/second.jpg",
            'thumb_path' => "photos/{$user->id}/second-thumb.jpg",
            'mime' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_tier' => 'hot',
            'taken_at' => '2024-06-02 10:00:00',
        ]);
        $album->cover_photo_id = $first->id;
        $album->save();

        $this->actingAs($user)->get('/photos?album='.$album->id)
            ->assertOk()
            ->assertSee('id="photos-album-cover-picker"', false)
            ->assertSee('data-photo-id="'.$second->id.'"', false);

        $this->actingAs($user)
            ->post('/photos/albums/'.$album->id.'/update', [
                'name' => '表紙編集',
                'visibility' => 'private',
                'cover_photo_id' => $second->id,
                'returnTo' => '/photos?album='.$album->id,
            ])
            ->assertRedirect();

        $this->assertSame($second->id, (int) $album->fresh()->cover_photo_id);
    }

    public function test_album_create_and_edit_can_hide_from_dashboard(): void
    {
        $user = $this->makeUser('dash-album@example.com');

        $this->actingAs($user)->get('/photos')
            ->assertOk()
            ->assertSee('id="photos-album-show-dashboard"', false)
            ->assertSee('id="photos-add-sheet-dashboard"', false);

        $this->actingAs($user)->post('/photos/albums', [
            'name' => '非表示アルバム',
            'visibility' => 'private',
            'show_on_dashboard' => '0',
        ])->assertRedirect();

        $album = PhotoAlbum::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertFalse((bool) $album->show_on_dashboard);

        $this->actingAs($user)->post('/photos/albums/'.$album->id.'/update', [
            'name' => '非表示アルバム',
            'visibility' => 'private',
            'show_on_dashboard' => '1',
            'returnTo' => '/photos?album='.$album->id,
        ])->assertRedirect();

        $this->assertTrue((bool) $album->fresh()->show_on_dashboard);
    }

    public function test_upload_can_hide_photo_from_dashboard(): void
    {
        config(['photos.disk' => 'public']);
        Storage::fake('public');

        $user = $this->makeUser('dash-photo@example.com');

        $this->actingAs($user)->post('/photos', [
            'show_on_dashboard' => '0',
            'photos' => [UploadedFile::fake()->image('private.jpg', 40, 40)],
        ])->assertRedirect();

        $photo = Photo::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertFalse((bool) $photo->show_on_dashboard);

        $this->actingAs($user)->post('/photos/'.$photo->id.'/dashboard', [
            'show_on_dashboard' => '1',
            'returnTo' => '/photos',
        ])->assertRedirect();

        $this->assertTrue((bool) $photo->fresh()->show_on_dashboard);
    }
}
