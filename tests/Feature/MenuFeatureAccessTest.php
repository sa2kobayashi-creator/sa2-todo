<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Enums\UserRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MenuFeatureAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(UserRole $role, string $email, ?array $menuFeatures = null): User
    {
        return User::create([
            'email' => $email,
            'display_name' => $role->label(),
            'password' => Hash::make('password'),
            'role' => $role,
            'menu_features' => $menuFeatures,
        ]);
    }

    public function test_user_custom_menu_features_override_role_defaults(): void
    {
        $user = $this->makeUser(UserRole::Light, 'light-menus@example.com', ['finance', 'travel']);

        $this->actingAs($user)->get('/finance')->assertOk();
        $this->actingAs($user)->get('/travel')->assertOk();
        $this->actingAs($user)->get('/music')->assertForbidden();
        $this->actingAs($user)->get('/settings')->assertForbidden();
    }

    public function test_empty_user_menu_features_remove_role_optional_menus(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'standard-none@example.com', []);

        $this->actingAs($user)->get('/finance')->assertForbidden();
        $this->actingAs($user)->get('/music')->assertForbidden();
        $this->actingAs($user)->get('/todos')->assertOk();
    }

    public function test_approved_group_menu_features_are_granted_to_members(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-menus@example.com');
        $user = $this->makeUser(UserRole::Light, 'light-group@example.com', []);

        $group = Group::create([
            'name' => 'Travel Team',
            'owner_user_id' => $admin->id,
            'status' => GroupStatus::Approved,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $user->id,
            'role' => 'member',
        ]);

        $this->actingAs($admin)->post("/admin/groups/{$group->id}/menus", [
            'menuFeatures' => ['travel', 'map'],
        ])->assertRedirect();

        $this->actingAs($user)->get('/travel')->assertOk();
        $this->actingAs($user)->get('/map')->assertOk();
        $this->actingAs($user)->get('/finance')->assertForbidden();
    }

    public function test_admin_can_save_user_menu_features(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-save-menus@example.com');
        $user = $this->makeUser(UserRole::Light, 'target-menus@example.com');

        $this->actingAs($admin)->post("/admin/users/{$user->id}/update", [
            'displayName' => $user->display_name,
            'email' => $user->email,
            'role' => UserRole::Light->value,
            'menuFeaturesConfigured' => '1',
            'menuFeatures' => ['music', 'video'],
        ])->assertRedirect();

        $user->refresh();
        $this->assertSame(['music', 'video'], $user->menu_features);
        $this->actingAs($user)->get('/music')->assertOk();
        $this->actingAs($user)->get('/finance')->assertForbidden();
    }
}
