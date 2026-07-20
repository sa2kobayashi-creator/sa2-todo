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
            ->assertHeader('Content-Type', 'image/jpeg');
    }
}
