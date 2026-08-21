<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaStorageSetting;
use App\Models\Note;
use App\Models\Photo;
use App\Models\TranslationApiKey;
use App\Models\User;
use App\Services\UserUsageLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 課金の土台になる2つの上限が、HTTP 経由で実際に効いているかを確認する。
 * 無料枠を超えても保存できてしまうと、そのまま原価に跳ね返る。
 */
class StorageQuotaAndUsageLimitTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email, UserRole $role = UserRole::Standard): User
    {
        return User::create([
            'email' => $email,
            'display_name' => $email,
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    /** 既存の使用量を作るためのダミー行（ファイル実体は不要） */
    private function fillUsage(User $user, int $bytes): void
    {
        Photo::create([
            'user_id' => $user->id,
            'album_id' => null,
            'path' => 'photos/'.$user->id.'/existing.jpg',
            'thumb_path' => null,
            'original_name' => 'existing.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => $bytes,
            'sort_order' => 0,
        ]);
    }

    public function test_upload_is_rejected_when_user_is_over_the_free_quota(): void
    {
        config([
            'photos.disk' => 'public',
            'photos.user_free_quota_bytes' => 1_000_000,
            'photos.block_uploads_over_free_quota' => true,
            'photos.paid_overage_enabled' => false,
        ]);
        Storage::fake('public');

        $user = $this->makeUser('quota-full@example.com');
        $this->fillUsage($user, 1_500_000);

        $this->actingAs($user)
            ->from('/photos')
            ->post('/photos', ['photos' => [UploadedFile::fake()->image('extra.jpg', 40, 40)]])
            ->assertSessionHas('error');

        $this->assertSame(1, Photo::query()->where('user_id', $user->id)->count());
    }

    public function test_upload_is_allowed_while_under_the_free_quota(): void
    {
        config([
            'photos.disk' => 'public',
            'photos.user_free_quota_bytes' => 50_000_000,
            'photos.block_uploads_over_free_quota' => true,
            'photos.paid_overage_enabled' => false,
        ]);
        Storage::fake('public');

        $user = $this->makeUser('quota-room@example.com');
        $this->fillUsage($user, 1_000);

        $this->actingAs($user)
            ->post('/photos', ['photos' => [UploadedFile::fake()->image('extra.jpg', 40, 40)]])
            ->assertRedirect();

        $this->assertSame(2, Photo::query()->where('user_id', $user->id)->count());
    }

    public function test_allowing_paid_overage_in_storage_settings_lets_an_over_quota_user_keep_uploading(): void
    {
        config([
            'photos.disk' => 'public',
            'photos.user_free_quota_bytes' => 1_000_000,
            'photos.block_uploads_over_free_quota' => true,
        ]);
        Storage::fake('public');

        MediaStorageSetting::query()->create([
            'provider' => MediaStorageSetting::PROVIDER_PIPELINE,
            'enabled' => true,
            'settings' => ['allow_paid_overage' => true],
            'secrets' => [],
        ]);

        $user = $this->makeUser('quota-paid@example.com');
        $this->fillUsage($user, 1_500_000);

        $this->actingAs($user)
            ->post('/photos', ['photos' => [UploadedFile::fake()->image('extra.jpg', 40, 40)]])
            ->assertRedirect();

        $this->assertSame(2, Photo::query()->where('user_id', $user->id)->count());
    }

    public function test_note_voice_parse_returns_429_when_the_daily_llm_limit_is_reached(): void
    {
        config(['usage_limits.llm_voice_requests_per_day' => 1]);

        // 音声入力はスーパー管理者向けの試作機能
        $user = $this->makeUser('voice-limit@example.com', UserRole::SuperAdmin);
        app(UserUsageLimitService::class)
            ->consume($user, UserUsageLimitService::FEATURE_LLM_VOICE_NOTE, 1);

        $this->actingAs($user)
            ->postJson('/notes/voice/parse', ['transcript' => '牛乳を買う'])
            ->assertStatus(429)
            ->assertJson(['ok' => false]);
    }

    public function test_todo_voice_parse_shares_the_same_daily_llm_pool(): void
    {
        config(['usage_limits.llm_voice_requests_per_day' => 1]);

        $user = $this->makeUser('voice-pool@example.com', UserRole::SuperAdmin);
        app(UserUsageLimitService::class)
            ->consume($user, UserUsageLimitService::FEATURE_LLM_VOICE_NOTE, 1);

        $this->actingAs($user)
            ->postJson('/todos/voice/parse', ['transcript' => '明日9時に病院'])
            ->assertStatus(429);
    }

    public function test_note_translate_returns_429_when_the_daily_character_limit_is_reached(): void
    {
        config(['usage_limits.translate_chars_per_day' => 5]);

        TranslationApiKey::create([
            'name' => 'Key',
            'api_key' => 'key:fx',
            'provider' => 'deepl',
            'api_url' => 'https://api-free.deepl.com/v2/translate',
            'is_active' => true,
        ]);

        $user = $this->makeUser('translate-limit@example.com', UserRole::SuperAdmin);
        app(UserUsageLimitService::class)
            ->consume($user, UserUsageLimitService::FEATURE_TRANSLATE, 5);

        $note = Note::create([
            'user_id' => $user->id,
            'title' => 'タイトル',
            'body' => 'これは翻訳したい本文です',
        ]);

        $this->actingAs($user)
            ->postJson("/notes/{$note->id}/translate")
            ->assertStatus(429)
            ->assertJson(['ok' => false]);
    }
}
