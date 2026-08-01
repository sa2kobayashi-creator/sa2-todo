<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PhotoAlbum;
use App\Models\User;
use App\Services\PhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhotoFolderImportTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email = 'folders@example.com'): User
    {
        return User::create([
            'email' => $email,
            'display_name' => 'Importer',
            'password' => Hash::make('password123'),
            'role' => UserRole::Standard,
        ]);
    }

    public function test_a_folder_name_becomes_an_album(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post('/photos/albums/for-folder', ['folder_name' => '沖縄2024'])
            ->assertOk()
            ->assertJson(['ok' => true, 'album' => ['name' => '沖縄2024']]);

        $this->assertDatabaseHas('photo_albums', [
            'user_id' => $user->id,
            'name' => '沖縄2024',
        ]);
    }

    public function test_a_repeated_folder_gets_its_own_numbered_album(): void
    {
        $user = $this->makeUser();
        $photos = app(PhotoService::class);

        $first = $photos->createAlbumForFolder((int) $user->id, '北海道');
        $second = $photos->createAlbumForFolder((int) $user->id, '北海道');
        $third = $photos->createAlbumForFolder((int) $user->id, '北海道');

        $this->assertSame('北海道', $first['name']);
        $this->assertSame('北海道 (2)', $second['name']);
        $this->assertSame('北海道 (3)', $third['name']);
        $this->assertSame(3, PhotoAlbum::query()->where('user_id', $user->id)->count());
    }

    public function test_another_users_album_name_does_not_force_a_number(): void
    {
        $mine = $this->makeUser('mine@example.com');
        $theirs = $this->makeUser('theirs@example.com');

        $photos = app(PhotoService::class);
        $photos->createAlbumForFolder((int) $theirs->id, '共通の名前');
        $album = $photos->createAlbumForFolder((int) $mine->id, '共通の名前');

        $this->assertSame('共通の名前', $album['name']);
    }

    public function test_path_separators_are_stripped_from_the_album_name(): void
    {
        $user = $this->makeUser();

        $album = app(PhotoService::class)->createAlbumForFolder((int) $user->id, " 旅行/沖縄\t2024 ");

        $this->assertSame('旅行 沖縄 2024', $album['name']);
    }

    public function test_a_very_long_folder_name_still_fits_with_its_number(): void
    {
        $user = $this->makeUser();
        $long = str_repeat('あ', 200);

        $photos = app(PhotoService::class);
        $first = $photos->createAlbumForFolder((int) $user->id, $long);
        $second = $photos->createAlbumForFolder((int) $user->id, $long);

        $this->assertSame(120, mb_strlen($first['name']));
        $this->assertSame(120, mb_strlen($second['name']));
        $this->assertStringEndsWith(' (2)', $second['name']);
    }

    public function test_an_empty_folder_name_is_rejected(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post('/photos/albums/for-folder', ['folder_name' => '   '])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertSame(0, PhotoAlbum::query()->count());
    }

    public function test_the_endpoint_is_closed_to_guests(): void
    {
        $this->post('/photos/albums/for-folder', ['folder_name' => 'x'])
            ->assertRedirect('/login');
    }

    public function test_the_photos_page_ships_the_folder_import_confirmation(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->get('/photos')
            ->assertOk()
            ->assertSee('id="photos-add-sheet-folders"', false)
            // フォルダ選択は webkitdirectory で行う。ブラウザや HTTPS の有無に左右されないこと
            ->assertSee('id="photos-folder-input"', false)
            ->assertSee('webkitdirectory', false)
            ->assertSee('id="photos-folder-import"', false)
            ->assertSee('id="photos-folder-import-list"', false)
            ->assertSee('フォルダごとアルバムにする');
    }
}
