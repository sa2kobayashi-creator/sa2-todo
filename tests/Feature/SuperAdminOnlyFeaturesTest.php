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

    public function test_translate_page_is_super_admin_only(): void
    {
        $standard = $this->makeUser(UserRole::Standard, 'std-translate@example.com');
        $admin = $this->makeUser(UserRole::Admin, 'admin-translate@example.com');
        $super = $this->makeUser(UserRole::SuperAdmin, 'super-translate@example.com');

        $this->actingAs($standard)->get('/translate')->assertForbidden();
        $this->actingAs($admin)->get('/translate')->assertForbidden();
        $this->actingAs($super)->get('/translate')->assertOk();
    }

    public function test_terms_and_privacy_pages_are_public(): void
    {
        $this->get('/terms')->assertOk()->assertSee('利用規約');
        $this->get('/privacy')->assertOk()->assertSee('プライバシー');
    }
}
