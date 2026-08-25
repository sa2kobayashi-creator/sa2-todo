<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GoogleMapsConfigService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantContractTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(UserRole $role, string $email, ?int $tenantId = null): User
    {
        return User::create([
            'email' => $email,
            'display_name' => $role->label(),
            'password' => Hash::make('password'),
            'role' => $role,
            'tenant_id' => $tenantId,
        ]);
    }

    public function test_super_admin_can_create_tenant_contract_with_owner(): void
    {
        $super = $this->makeUser(UserRole::SuperAdmin, 'ops@example.com');

        $this->actingAs($super)->post('/admin/tenants', [
            'name' => '山田家',
            'max_users' => 5,
            'allow_own_keys' => '1',
            'owner_display_name' => '山田太郎',
            'owner_email' => 'yamada@example.com',
            'owner_password' => 'password123',
        ])->assertRedirect('/admin/tenants');

        $tenant = Tenant::query()->where('name', '山田家')->first();
        $this->assertNotNull($tenant);
        $owner = User::query()->where('email', 'yamada@example.com')->first();
        $this->assertNotNull($owner);
        $this->assertSame(UserRole::Admin, $owner->roleEnum());
        $this->assertSame($tenant->id, $owner->tenant_id);
        $this->assertSame($owner->id, $tenant->owner_user_id);
        $this->assertTrue($tenant->isOnTrial());
    }

    public function test_tenant_admin_sees_only_own_users_and_cannot_see_others(): void
    {
        $super = $this->makeUser(UserRole::SuperAdmin, 'ops-scope@example.com');
        $this->actingAs($super)->post('/admin/tenants', [
            'name' => 'A家',
            'max_users' => 5,
            'allow_own_keys' => '1',
            'owner_display_name' => 'A代表',
            'owner_email' => 'a-owner@example.com',
            'owner_password' => 'password123',
        ]);
        $this->actingAs($super)->post('/admin/tenants', [
            'name' => 'B家',
            'max_users' => 5,
            'allow_own_keys' => '1',
            'owner_display_name' => 'B代表',
            'owner_email' => 'b-owner@example.com',
            'owner_password' => 'password123',
        ]);

        $ownerA = User::query()->where('email', 'a-owner@example.com')->firstOrFail();
        $ownerB = User::query()->where('email', 'b-owner@example.com')->firstOrFail();
        $personal = $this->makeUser(UserRole::Standard, 'personal@example.com');

        $this->actingAs($ownerA)->get('/admin/users')
            ->assertOk()
            ->assertSee('A代表')
            ->assertDontSee('B代表')
            ->assertDontSee('personal@example.com');

        $this->actingAs($ownerA)->get('/admin/users/'.$ownerB->id)->assertForbidden();
        $this->actingAs($ownerA)->get('/admin/users/'.$personal->id)->assertForbidden();
        $this->actingAs($ownerA)->get('/admin/tenants')->assertForbidden();
    }

    public function test_tenant_storage_keys_do_not_overwrite_platform_keys(): void
    {
        config(['services.google_maps.api_key' => '']);
        $super = $this->makeUser(UserRole::SuperAdmin, 'ops-keys@example.com');
        $this->actingAs($super)->post('/settings/api/google-maps', [
            'enabled' => '1',
            'api_key' => 'AIzaSyPlatformMapsKey0000000000000',
        ])->assertRedirect();

        $this->actingAs($super)->post('/admin/tenants', [
            'name' => '鍵テスト家',
            'max_users' => 5,
            'allow_own_keys' => '1',
            'owner_display_name' => '鍵代表',
            'owner_email' => 'keys-owner@example.com',
            'owner_password' => 'password123',
        ]);
        $owner = User::query()->where('email', 'keys-owner@example.com')->firstOrFail();

        $this->actingAs($owner)->post('/settings/api/google-maps', [
            'enabled' => '1',
            'api_key' => 'AIzaSyTenantMapsKey000000000000000',
        ])->assertRedirect();

        $this->assertDatabaseHas('media_storage_settings', [
            'provider' => 'google_maps',
            'tenant_scope' => 0,
        ]);
        $this->assertDatabaseHas('media_storage_settings', [
            'provider' => 'google_maps',
            'tenant_scope' => $owner->tenant_id,
        ]);

        app(TenantContext::class)->set(null);
        $this->assertSame(
            'AIzaSyPlatformMapsKey0000000000000',
            app(GoogleMapsConfigService::class)->apiKey()
        );

        app(TenantContext::class)->set($owner->tenant_id);
        $this->assertSame(
            'AIzaSyTenantMapsKey000000000000000',
            app(GoogleMapsConfigService::class)->apiKey()
        );
    }

    public function test_tenant_admin_cannot_change_platform_invite_code_or_web_push(): void
    {
        $super = $this->makeUser(UserRole::SuperAdmin, 'ops-lock@example.com');
        $this->actingAs($super)->post('/admin/tenants', [
            'name' => '制限家',
            'max_users' => 5,
            'allow_own_keys' => '1',
            'owner_display_name' => '制限代表',
            'owner_email' => 'lock-owner@example.com',
            'owner_password' => 'password123',
        ]);
        $owner = User::query()->where('email', 'lock-owner@example.com')->firstOrFail();

        $this->actingAs($owner)->post('/admin/users/registration', [
            'inviteCode' => 'stolen-code',
        ])->assertForbidden();

        $this->actingAs($owner)->post('/settings/api/web-push', [
            'enabled' => '1',
        ])->assertForbidden();
    }

    public function test_tenant_user_limit_is_enforced(): void
    {
        $super = $this->makeUser(UserRole::SuperAdmin, 'ops-limit@example.com');
        $this->actingAs($super)->post('/admin/tenants', [
            'name' => '上限家',
            'max_users' => 1,
            'allow_own_keys' => '1',
            'owner_display_name' => '上限代表',
            'owner_email' => 'limit-owner@example.com',
            'owner_password' => 'password123',
        ]);
        $owner = User::query()->where('email', 'limit-owner@example.com')->firstOrFail();

        $this->actingAs($owner)->post('/admin/users', [
            'displayName' => '二人目',
            'email' => 'second@example.com',
            'password' => 'password123',
            'role' => UserRole::Light->value,
        ])->assertRedirect('/admin/users');

        $this->assertNull(User::query()->where('email', 'second@example.com')->first());
    }

    public function test_group_invite_cannot_cross_tenants(): void
    {
        $super = $this->makeUser(UserRole::SuperAdmin, 'ops-group@example.com');
        $this->actingAs($super)->post('/admin/tenants', [
            'name' => 'G1',
            'max_users' => 5,
            'allow_own_keys' => '1',
            'owner_display_name' => 'G1代表',
            'owner_email' => 'g1@example.com',
            'owner_password' => 'password123',
        ]);
        $this->actingAs($super)->post('/admin/tenants', [
            'name' => 'G2',
            'max_users' => 5,
            'allow_own_keys' => '1',
            'owner_display_name' => 'G2代表',
            'owner_email' => 'g2@example.com',
            'owner_password' => 'password123',
        ]);
        $owner1 = User::query()->where('email', 'g1@example.com')->firstOrFail();
        $owner2 = User::query()->where('email', 'g2@example.com')->firstOrFail();

        $groups = app(\App\Services\GroupService::class);
        $created = $groups->create((int) $owner1->id, '家族', null, (int) $owner1->id);

        $this->expectException(\InvalidArgumentException::class);
        $groups->inviteByEmail((int) $owner1->id, (int) $created['id'], 'g2@example.com');
    }

    public function test_new_tenant_defaults_to_five_users_including_owner(): void
    {
        $tenant = app(\App\Services\TenantContractService::class)->createWithOwner([
            'name' => '既定上限家',
            'owner_email' => 'default-max@example.com',
            'owner_display_name' => '既定代表',
            'owner_password' => 'password123',
        ]);

        $this->assertSame(5, $tenant->max_users);
        $this->assertSame(1, $tenant->userCount());
        $this->assertNotNull($tenant->trial_ends_at);
        $this->assertTrue($tenant->isOnTrial());
        $this->assertTrue($tenant->isOwner(User::query()->where('email', 'default-max@example.com')->first()));
    }

    public function test_tenant_admin_cannot_create_another_admin(): void
    {
        $super = $this->makeUser(UserRole::SuperAdmin, 'ops-admin-seat@example.com');
        $this->actingAs($super)->post('/admin/tenants', [
            'name' => '管理者1名家',
            'max_users' => 5,
            'allow_own_keys' => '1',
            'owner_display_name' => '代表のみ',
            'owner_email' => 'one-admin@example.com',
            'owner_password' => 'password123',
        ]);
        $owner = User::query()->where('email', 'one-admin@example.com')->firstOrFail();

        $this->actingAs($owner)->post('/admin/users', [
            'displayName' => 'もう一人の管理者',
            'email' => 'second-admin@example.com',
            'password' => 'password123',
            'role' => UserRole::Admin->value,
        ])->assertSessionHasErrors('role');

        $this->assertNull(User::query()->where('email', 'second-admin@example.com')->first());
    }

    public function test_super_admin_cannot_promote_second_tenant_admin_or_demote_owner(): void
    {
        $super = $this->makeUser(UserRole::SuperAdmin, 'ops-promote@example.com');
        $this->actingAs($super)->post('/admin/tenants', [
            'name' => '昇格禁止家',
            'max_users' => 5,
            'allow_own_keys' => '1',
            'owner_display_name' => '昇格代表',
            'owner_email' => 'promote-owner@example.com',
            'owner_password' => 'password123',
        ]);
        $owner = User::query()->where('email', 'promote-owner@example.com')->firstOrFail();

        $this->actingAs($owner)->post('/admin/users', [
            'displayName' => 'メンバー',
            'email' => 'promote-member@example.com',
            'password' => 'password123',
            'role' => UserRole::Light->value,
        ])->assertRedirect('/admin/users');
        $member = User::query()->where('email', 'promote-member@example.com')->firstOrFail();

        $this->actingAs($super)->post('/admin/users/'.$member->id.'/update', [
            'displayName' => 'メンバー',
            'email' => 'promote-member@example.com',
            'role' => UserRole::Admin->value,
        ])->assertRedirect('/admin/users/'.$member->id.'/edit');
        $this->assertSame(UserRole::Light, $member->fresh()->roleEnum());

        $this->actingAs($super)->post('/admin/users/'.$owner->id.'/update', [
            'displayName' => '昇格代表',
            'email' => 'promote-owner@example.com',
            'role' => UserRole::Light->value,
        ])->assertRedirect('/admin/users/'.$owner->id.'/edit');
        $this->assertSame(UserRole::Admin, $owner->fresh()->roleEnum());
    }

    public function test_contract_owner_cannot_be_deleted(): void
    {
        $super = $this->makeUser(UserRole::SuperAdmin, 'ops-delete-owner@example.com');
        $this->actingAs($super)->post('/admin/tenants', [
            'name' => '削除禁止家',
            'max_users' => 5,
            'allow_own_keys' => '1',
            'owner_display_name' => '削除代表',
            'owner_email' => 'keep-owner@example.com',
            'owner_password' => 'password123',
        ]);
        $owner = User::query()->where('email', 'keep-owner@example.com')->firstOrFail();

        $this->actingAs($super)->post('/admin/users/'.$owner->id.'/delete')->assertRedirect('/admin/users');
        $this->assertNotNull(User::query()->find($owner->id));
    }

    public function test_super_admin_tenant_screen_shows_published_offer(): void
    {
        $super = $this->makeUser(UserRole::SuperAdmin, 'ops-offer@example.com');

        $this->actingAs($super)->get('/admin/tenants')
            ->assertOk()
            ->assertSee(__('試用終了日'), false)
            ->assertSee(number_format((int) config('commercial.tenant_monthly_yen', 3980)), false);
    }
}
