<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhotoCloudinaryEditOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email): User
    {
        return User::create([
            'email' => $email,
            'display_name' => 'Owner',
            'password' => Hash::make('password'),
            'role' => UserRole::Standard,
        ]);
    }

    private function makePhoto(User $user): Photo
    {
        return Photo::create([
            'user_id' => $user->id,
            'path' => 'photos/'.$user->id.'/sample.jpg',
            'original_name' => 'sample.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 1024,
        ]);
    }

    public function test_cancel_rejects_a_temp_asset_of_another_users_photo(): void
    {
        $owner = $this->makeUser('cloudinary-owner@example.com');
        $attacker = $this->makeUser('cloudinary-attacker@example.com');
        $photo = $this->makePhoto($owner);

        $this->actingAs($attacker)
            ->postJson("/photos/{$photo->id}/cloudinary-edit/cancel", [
                'tempPublicId' => 'sa2todo/edit_tmp/photo_'.$photo->id.'_abcd1234',
            ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_cancel_rejects_a_temp_asset_that_belongs_to_a_different_photo(): void
    {
        $owner = $this->makeUser('cloudinary-owner-2@example.com');
        $photo = $this->makePhoto($owner);

        $this->actingAs($owner)
            ->postJson("/photos/{$photo->id}/cloudinary-edit/cancel", [
                'tempPublicId' => 'sa2todo/edit_tmp/photo_'.($photo->id + 999).'_abcd1234',
            ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_cancel_without_a_temp_asset_is_a_no_op(): void
    {
        $owner = $this->makeUser('cloudinary-owner-3@example.com');
        $photo = $this->makePhoto($owner);

        $this->actingAs($owner)
            ->postJson("/photos/{$photo->id}/cloudinary-edit/cancel", ['tempPublicId' => ''])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }
}
