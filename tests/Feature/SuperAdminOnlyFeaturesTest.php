<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SuperAdminOnlyFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(UserRole $role, string $email): User
    {
        return User::create([
            'email' => $email,
            'display_name' => $role->label(),
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    public function test_enhance_ui_is_hidden_from_admin(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-enhance-ui@example.com');
        $super = $this->makeUser(UserRole::SuperAdmin, 'super-enhance-ui@example.com');

        $this->actingAs($admin)->get('/settings?section=holidays')
            ->assertOk()
            ->assertSee('API設定', false)
            ->assertDontSee('鮮明化設定', false)
            ->assertDontSee('AI鮮明化はスーパー管理者のみが利用・設定できます', false);

        $this->actingAs($admin)->get('/settings?section=storage')
            ->assertOk()
            ->assertSee('API設定', false)
            ->assertDontSee('AI鮮明化は「API設定」で行います。', false);

        $this->actingAs($admin)->get('/photos')
            ->assertOk()
            ->assertDontSee('AIで鮮明化', false)
            ->assertDontSee('AI鮮明化', false)
            ->assertDontSee('鮮明化件数', false);

        $this->actingAs($admin)->get('/settings?section=enhance')
            ->assertOk()
            ->assertSee('Google マップ（Map / Transit）', false)
            ->assertSee('Google カレンダー（OAuth アプリ）', false)
            ->assertDontSee('写真鮮明化', false);

        $this->actingAs($admin)->get('/settings?section=usage')
            ->assertOk()
            ->assertDontSee('AI鮮明化', false)
            ->assertDontSee('Photos AI鮮明化', false)
            ->assertDontSee('鮮明化リクエスト', false);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('AI鮮明化', false);

        $this->actingAs($super)->get('/settings?section=enhance')
            ->assertOk()
            ->assertSee('API設定', false)
            ->assertSee('写真鮮明化', false)
            ->assertSee('Google マップ（Map / Transit）', false)
            ->assertDontSee('AI鮮明化はスーパー管理者のみが利用・設定できます', false);

        $this->actingAs($super)->get('/photos')
            ->assertOk()
            ->assertSee('初期設定', false);

        $this->actingAs($super)->get('/dashboard')
            ->assertOk()
            ->assertSee('AI鮮明化', false);

        $this->actingAs($super)->get('/settings?section=usage')
            ->assertOk()
            ->assertSee('Photos AI鮮明化', false);
    }

    public function test_storage_stats_cache_does_not_leak_enhance_to_admin(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-stats-cache@example.com');
        $photos = app(\App\Services\PhotoService::class);

        $photos->forgetStorageStatsCache((int) $admin->id);
        $withEnhance = $photos->storageStats((int) $admin->id, true);
        $withoutEnhance = $photos->storageStats((int) $admin->id, false);

        $this->assertTrue(collect($withEnhance['providers'] ?? [])->contains(
            fn (array $provider) => ($provider['role'] ?? '') === 'AI鮮明化'
        ));
        $this->assertFalse(collect($withoutEnhance['providers'] ?? [])->contains(
            fn (array $provider) => ($provider['role'] ?? '') === 'AI鮮明化'
        ));
        $this->assertFalse((bool) ($withoutEnhance['enhanceReady'] ?? false));
    }

    public function test_enhance_endpoint_is_super_admin_only(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-enhance@example.com');
        $super = $this->makeUser(UserRole::SuperAdmin, 'super-enhance@example.com');

        $this->actingAs($admin)
            ->postJson('/photos/1/stability-enhance')
            ->assertForbidden();

        // Super admin gets past the role gate (may 404/422 if photo missing)
        $this->actingAs($super)
            ->postJson('/photos/1/stability-enhance')
            ->assertStatus(422);
    }

    public function test_translate_page_is_available_to_signed_in_users(): void
    {
        $standard = $this->makeUser(UserRole::Standard, 'std-translate@example.com');
        $admin = $this->makeUser(UserRole::Admin, 'admin-translate@example.com');
        $light = $this->makeUser(UserRole::Light, 'light-translate@example.com');

        $this->actingAs($standard)->get('/translate')->assertOk();
        $this->actingAs($admin)->get('/translate')->assertOk();
        $this->actingAs($light)->get('/translate')->assertOk();
    }

    public function test_terms_and_privacy_pages_are_public(): void
    {
        $this->get('/terms')->assertOk()->assertSee('利用規約');
        $this->get('/privacy')->assertOk()->assertSee('プライバシー');
    }
}
