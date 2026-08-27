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
        $user = $this->makeUser(UserRole::Light, 'light-menus@example.com', ['finance', 'map']);

        $this->actingAs($user)->get('/finance')->assertOk();
        $this->actingAs($user)->get('/map')->assertOk();
        $this->actingAs($user)->get('/travel')->assertNotFound();
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
        // null = ロール既定。グループ付与が加算される
        $user = $this->makeUser(UserRole::Light, 'light-group@example.com', null);

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
            'menuFeatures' => ['map'],
        ])->assertRedirect();

        $this->actingAs($user)->get('/travel')->assertNotFound();
        $this->actingAs($user)->get('/map')->assertOk();
        $this->actingAs($user)->get('/finance')->assertForbidden();
    }

    public function test_explicit_empty_user_menus_ignore_group_grants(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'super-group-lock@example.com');
        $user = $this->makeUser(UserRole::Standard, 'standard-group-lock@example.com', null);

        $group = Group::create([
            'name' => 'Full Menus',
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
            'menuFeatures' => ['finance', 'transit', 'music', 'map'],
        ])->assertRedirect();

        $this->actingAs($user)->get('/finance')->assertOk();

        $this->actingAs($admin)->post("/admin/users/{$user->id}/update", [
            'displayName' => $user->display_name,
            'email' => $user->email,
            'role' => UserRole::Standard->value,
            // 全部外す
        ])->assertRedirect();

        $user->refresh();
        $this->assertSame([], $user->menu_features);
        $this->assertSame([], $user->effectiveMenuFeatures());

        $this->actingAs($user)->get('/finance')->assertForbidden();
        $this->actingAs($user)->get('/music')->assertForbidden();
        $this->actingAs($user)->get('/todos')->assertOk();

        $dashboard = $this->actingAs($user)->get('/dashboard')->assertOk();
        $dashboard->assertDontSee('href="/finance"', false);
        $dashboard->assertDontSee('href="/music"', false);
        $dashboard->assertDontSee('家計簿');
    }

    public function test_super_admin_can_restrict_standard_user_menus_and_header(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'super-restrict@example.com');
        $user = $this->makeUser(UserRole::Standard, 'standard-restrict@example.com', null);
        // 以前に全メニュー表示を保存していたケースを再現
        $user->header_nav = ['dashboard', 'todos', 'notes', 'photos', 'finance', 'transit', 'map', 'music', 'video'];
        $user->footer_nav = ['dashboard', 'todos', 'notes', 'photos', 'finance'];
        $user->save();

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('家計簿', false)
            ->assertSee('音楽', false);

        $this->actingAs($admin)->post("/admin/users/{$user->id}/update", [
            'displayName' => $user->display_name,
            'email' => $user->email,
            'role' => UserRole::Standard->value,
            'menuFeatures' => ['music'],
        ])->assertRedirect();

        $user->refresh();
        $this->assertSame(['music'], $user->menu_features);
        $this->assertSame(['music'], $user->effectiveMenuFeatures());
        $this->assertSame(['dashboard', 'todos', 'notes', 'photos', 'music'], $user->header_nav);
        $this->assertSame(['dashboard', 'todos', 'notes', 'photos'], $user->footer_nav);

        $this->actingAs($user)->get('/music')->assertOk();
        $this->actingAs($user)->get('/finance')->assertForbidden();
        $this->actingAs($user)->get('/mypage')
            ->assertOk()
            ->assertSee('is-denied', false)
            ->assertSee(__('利用不可'));

        $dashboard = $this->actingAs($user)->get('/dashboard')->assertOk();
        $dashboard->assertSee('href="/music"', false);
        $dashboard->assertDontSee('href="/finance"', false);
        $dashboard->assertDontSee('href="/transit"', false);
        $dashboard->assertDontSee('href="/map"', false);
        $dashboard->assertDontSee('家計簿');
        $dashboard->assertDontSee('路線検索');
    }
}
