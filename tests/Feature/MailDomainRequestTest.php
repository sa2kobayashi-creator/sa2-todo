<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MailDomainRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MailDomainRequestTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(UserRole $role, string $email, array $extra = []): User
    {
        return User::create(array_merge([
            'email' => $email,
            'display_name' => $role->label(),
            'password' => Hash::make('password'),
            'role' => $role,
        ], $extra));
    }

    public function test_light_user_without_addon_cannot_request_mailbox(): void
    {
        $user = $this->makeUser(UserRole::Light, 'user@example.com');

        $this->actingAs($user)
            ->post('/mail/domain-requests', ['local_part' => 'alice'])
            ->assertRedirect('/mail?tab=domain')
            ->assertSessionHas('error');

        $this->assertSame(0, MailDomainRequest::query()->count());
    }

    public function test_light_with_active_subscription_still_needs_mailbox_addon(): void
    {
        $user = $this->makeUser(UserRole::Light, 'light-sub-mail@example.com', [
            'subscription_status' => 'active',
        ]);

        $this->actingAs($user)
            ->post('/mail/domain-requests', ['local_part' => 'alice'])
            ->assertRedirect('/mail?tab=domain')
            ->assertSessionHas('error');

        $this->assertSame(0, MailDomainRequest::query()->count());
    }

    public function test_tenant_light_user_can_request_mailbox_without_addon(): void
    {
        $tenant = app(\App\Services\TenantContractService::class)->createWithOwner([
            'name' => 'メール込み家',
            'owner_email' => 'mail-owner@example.com',
            'owner_display_name' => 'メール代表',
            'owner_password' => 'password123',
        ]);
        $light = $this->makeUser(UserRole::Light, 'tenant-light-mail@example.com', [
            'tenant_id' => $tenant->id,
        ]);

        $this->actingAs($light)
            ->post('/mail/domain-requests', ['local_part' => 'family'])
            ->assertRedirect('/mail?tab=domain');

        $this->assertDatabaseHas('mail_domain_requests', [
            'user_id' => $light->id,
            'local_part' => 'family',
            'domain' => 'sa2-plus.com',
            'status' => MailDomainRequest::STATUS_PENDING,
        ]);
    }

    public function test_standard_user_can_request_mailbox_without_addon_flag(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'standard-mail@example.com');

        $this->actingAs($user)
            ->post('/mail/domain-requests', ['local_part' => 'alice'])
            ->assertRedirect('/mail?tab=domain');

        $this->assertDatabaseHas('mail_domain_requests', [
            'user_id' => $user->id,
            'local_part' => 'alice',
            'domain' => 'sa2-plus.com',
            'status' => MailDomainRequest::STATUS_PENDING,
        ]);
    }

    public function test_user_with_addon_can_request_one_mailbox(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'user@example.com', [
            'mailbox_addon_active' => true,
        ]);

        $this->actingAs($user)
            ->post('/mail/domain-requests', ['local_part' => 'alice'])
            ->assertRedirect('/mail?tab=domain');

        $this->assertDatabaseHas('mail_domain_requests', [
            'user_id' => $user->id,
            'local_part' => 'alice',
            'domain' => 'sa2-plus.com',
            'status' => MailDomainRequest::STATUS_PENDING,
            'provisioning_mode' => 'manual',
        ]);

        $this->actingAs($user)
            ->post('/mail/domain-requests', ['local_part' => 'bob'])
            ->assertRedirect('/mail?tab=domain');

        $this->assertSame(1, MailDomainRequest::query()->where('user_id', $user->id)->count());
    }

    public function test_domain_tab_shows_pricing_and_addon_gate(): void
    {
        $user = $this->makeUser(UserRole::Light, 'light-mail@example.com');

        $this->actingAs($user)
            ->get('/mail?tab=domain')
            ->assertOk()
            ->assertSee('月額', false)
            ->assertSee('300', false)
            ->assertSee('まだ有料オプションが有効になっていません', false);

        $standard = $this->makeUser(UserRole::Standard, 'std-mail-tab@example.com');
        $this->actingAs($standard)
            ->get('/mail?tab=domain')
            ->assertOk()
            ->assertSee('スタンダード契約に', false)
            ->assertSee('運営者が手動', false)
            ->assertDontSee('まだ有料オプションが有効になっていません', false);
    }

    public function test_admin_mail_requests_page_explains_manual_lolipop_work(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-mail-ui@example.com');

        $this->actingAs($admin)
            ->get('/admin/mail-requests')
            ->assertOk()
            ->assertSee('ロリポップ管理画面で手動', false)
            ->assertSee('作成済みにする（手動）', false);
    }

    public function test_user_can_cancel_pending_request(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'cancel@example.com', [
            'mailbox_addon_active' => true,
        ]);
        $req = MailDomainRequest::create([
            'user_id' => $user->id,
            'local_part' => 'cancelme',
            'domain' => 'sa2-plus.com',
            'status' => MailDomainRequest::STATUS_PENDING,
            'provisioning_mode' => 'manual',
        ]);

        $this->actingAs($user)
            ->post("/mail/domain-requests/{$req->id}/cancel")
            ->assertRedirect('/mail?tab=domain');

        $this->assertSame(MailDomainRequest::STATUS_REJECTED, $req->fresh()->status);
    }

    public function test_user_can_request_cancel_of_provisioned_mailbox(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'leave@example.com', [
            'mailbox_addon_active' => true,
        ]);
        $req = MailDomainRequest::create([
            'user_id' => $user->id,
            'local_part' => 'leave',
            'domain' => 'sa2-plus.com',
            'status' => MailDomainRequest::STATUS_PROVISIONED,
            'provisioning_mode' => 'manual',
        ]);

        $this->actingAs($user)
            ->post("/mail/domain-requests/{$req->id}/cancel")
            ->assertRedirect('/mail?tab=domain');

        $req->refresh();
        $this->assertSame(MailDomainRequest::STATUS_PROVISIONED, $req->status);
        $this->assertNotNull($req->cancel_requested_at);
    }

    public function test_reserved_local_part_is_rejected(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'user2@example.com', [
            'mailbox_addon_active' => true,
        ]);

        $this->actingAs($user)
            ->post('/mail/domain-requests', ['local_part' => 'admin'])
            ->assertRedirect('/mail?tab=domain');

        $this->assertSame(0, MailDomainRequest::query()->count());
    }

    public function test_admin_approve_activates_mailbox_addon(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'member@example.com', [
            'mailbox_addon_active' => true,
        ]);
        $admin = $this->makeUser(UserRole::Admin, 'admin@example.com');

        $req = MailDomainRequest::create([
            'user_id' => $user->id,
            'local_part' => 'member',
            'domain' => 'sa2-plus.com',
            'status' => MailDomainRequest::STATUS_PENDING,
            'provisioning_mode' => 'manual',
        ]);

        $user->forceFill(['mailbox_addon_active' => false])->save();

        $this->actingAs($admin)
            ->post("/admin/mail-requests/{$req->id}/approve")
            ->assertRedirect('/admin/mail-requests');

        $this->assertTrue((bool) $user->fresh()->mailbox_addon_active);

        $this->actingAs($admin)
            ->post("/admin/mail-requests/{$req->id}/provision")
            ->assertRedirect('/admin/mail-requests');

        $this->assertSame(MailDomainRequest::STATUS_PROVISIONED, $req->fresh()->status);
    }

    public function test_admin_suspend_clears_mailbox_addon(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'suspend@example.com', [
            'mailbox_addon_active' => true,
        ]);
        $admin = $this->makeUser(UserRole::Admin, 'admin2@example.com');

        $req = MailDomainRequest::create([
            'user_id' => $user->id,
            'local_part' => 'suspendme',
            'domain' => 'sa2-plus.com',
            'status' => MailDomainRequest::STATUS_PROVISIONED,
            'provisioning_mode' => 'manual',
            'cancel_requested_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post("/admin/mail-requests/{$req->id}/suspend")
            ->assertRedirect('/admin/mail-requests');

        $this->assertSame(MailDomainRequest::STATUS_SUSPENDED, $req->fresh()->status);
        $this->assertFalse((bool) $user->fresh()->mailbox_addon_active);
    }

    public function test_staff_can_request_without_addon(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'staff-mail@example.com');

        $this->actingAs($admin)
            ->post('/mail/domain-requests', ['local_part' => 'opsbox'])
            ->assertRedirect('/mail?tab=domain');

        $this->assertSame(1, MailDomainRequest::query()->where('local_part', 'opsbox')->count());
    }
}
