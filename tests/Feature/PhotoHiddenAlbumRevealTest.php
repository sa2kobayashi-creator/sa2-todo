<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PhotoAlbum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhotoHiddenAlbumRevealTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'email' => 'hidden-reveal@example.com',
            'display_name' => 'Hidden',
            'password' => Hash::make('password123'),
            'role' => UserRole::Standard,
        ]);
    }

    public function test_hiding_reveal_while_on_hidden_album_redirects_to_all(): void
    {
        $user = $this->makeUser();
        $hidden = PhotoAlbum::create([
            'user_id' => $user->id,
            'name' => 'Subic_hidden',
            'visibility' => 'private',
            'is_hidden' => true,
        ]);

        $sessionKey = 'photos_reveal_hidden_'.$user->id;

        $this->actingAs($user)
            ->withSession([$sessionKey => true])
            ->postJson('/photos/albums/reveal-hidden', [
                'returnTo' => '/photos?album='.$hidden->id,
            ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'revealed' => false,
                'redirectTo' => '/photos',
            ]);

        $this->assertFalse((bool) session($sessionKey));
    }

    public function test_hiding_reveal_on_normal_album_does_not_force_all(): void
    {
        $user = $this->makeUser();
        $album = PhotoAlbum::create([
            'user_id' => $user->id,
            'name' => 'Travel',
            'visibility' => 'private',
            'is_hidden' => false,
        ]);

        $sessionKey = 'photos_reveal_hidden_'.$user->id;

        $this->actingAs($user)
            ->withSession([$sessionKey => true])
            ->postJson('/photos/albums/reveal-hidden', [
                'returnTo' => '/photos?album='.$album->id,
            ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'revealed' => false,
                'redirectTo' => null,
            ]);
    }
}
