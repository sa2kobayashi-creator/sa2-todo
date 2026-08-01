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

class GroupCreationPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(UserRole $role, string $email): User
    {
        return User::create([
            'email' => $email,
            'display_name' => $role->label(),
            'password' => Hash::make('password123'),
            'role' => $role,
        ]);
    }

    public function test_light_user_cannot_reach_groups_at_all(): void
    {
        $light = $this->makeUser(UserRole::Light, 'light-groups@example.com');

        $this->actingAs($light)->get('/groups')->assertForbidden();
        $this->actingAs($light)->post('/groups', ['name' => 'こっそり'])->assertForbidden();

        $this->assertSame(0, Group::query()->count());
    }

    public function test_light_user_does_not_see_the_group_menu(): void
    {
        $light = $this->makeUser(UserRole::Light, 'light-menu@example.com');

        $this->actingAs($light)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('href="/groups"', false);
    }

    public function test_standard_user_sees_the_group_menu_and_creates_a_pending_group(): void
    {
        $standard = $this->makeUser(UserRole::Standard, 'standard-groups@example.com');

        $this->actingAs($standard)->get('/dashboard')
            ->assertOk()
            ->assertSee('href="/groups"', false);

        $this->actingAs($standard)->post('/groups', [
            'name' => '企画チーム',
            'description' => '社内の企画',
        ])->assertRedirect();

        $group = Group::query()->where('name', '企画チーム')->firstOrFail();
        $this->assertSame(GroupStatus::Pending, $group->status);
        $this->assertSame($standard->id, (int) $group->owner_user_id);
        $this->assertTrue(
            GroupMember::query()->where('group_id', $group->id)->where('user_id', $standard->id)->exists()
        );
    }

    public function test_admin_created_group_skips_approval(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-groups@example.com');

        $this->actingAs($admin)->post('/groups', ['name' => '管理チーム'])
            ->assertRedirect();

        $group = Group::query()->where('name', '管理チーム')->firstOrFail();
        $this->assertSame(GroupStatus::Approved, $group->status);
        $this->assertSame($admin->id, (int) $group->reviewed_by);
        $this->assertNotNull($group->reviewed_at);
    }

    public function test_admin_can_add_an_approved_group_from_group_management(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-add@example.com');
        $owner = $this->makeUser(UserRole::Standard, 'group-owner@example.com');

        $this->actingAs($admin)->get('/admin/groups')
            ->assertOk()
            ->assertSee('グループを追加');

        $this->actingAs($admin)->post('/admin/groups', [
            'name' => '営業部',
            'description' => '営業のメンバー',
            'owner_user_id' => $owner->id,
        ])->assertRedirect();

        $group = Group::query()->where('name', '営業部')->firstOrFail();
        $this->assertSame(GroupStatus::Approved, $group->status);
        $this->assertSame($owner->id, (int) $group->owner_user_id);
        $this->assertSame($admin->id, (int) $group->reviewed_by);
        $this->assertTrue(
            GroupMember::query()->where('group_id', $group->id)->where('user_id', $owner->id)->exists()
        );
    }

    public function test_admin_group_defaults_to_the_admin_as_owner(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-self-owner@example.com');

        $this->actingAs($admin)->post('/admin/groups', ['name' => '運営'])
            ->assertRedirect();

        $group = Group::query()->where('name', '運営')->firstOrFail();
        $this->assertSame($admin->id, (int) $group->owner_user_id);
    }

    public function test_non_admin_cannot_use_the_admin_group_endpoint(): void
    {
        $standard = $this->makeUser(UserRole::Standard, 'standard-admin-try@example.com');

        $this->actingAs($standard)->post('/admin/groups', ['name' => '勝手に'])
            ->assertForbidden();

        $this->assertSame(0, Group::query()->count());
    }
}
