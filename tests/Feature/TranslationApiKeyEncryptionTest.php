<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\TranslationApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TranslationApiKeyEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_key_is_encrypted_at_rest_and_masked_in_edit_json(): void
    {
        $admin = User::create([
            'email' => 'sa@example.com',
            'display_name' => 'SA',
            'password' => Hash::make('password'),
            'role' => UserRole::SuperAdmin,
        ]);

        $key = TranslationApiKey::create([
            'name' => 'DeepL Test',
            'api_key' => 'test-secret-key-fx:abcdefgh',
            'provider' => 'deepl',
            'is_active' => true,
            'priority' => 1,
        ]);

        $raw = DB::table('translation_api_keys')->where('id', $key->id)->value('api_key');
        $this->assertNotSame('test-secret-key-fx:abcdefgh', $raw);
        $this->assertSame('test-secret-key-fx:abcdefgh', Crypt::decryptString($raw));

        $this->actingAs($admin)
            ->getJson("/settings/translation-keys/{$key->id}/edit")
            ->assertOk()
            ->assertJsonPath('api_key', '')
            ->assertJsonPath('api_key_masked', '••••••••efgh');
    }
}
