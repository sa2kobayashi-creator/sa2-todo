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

    private function makeUser(UserRole $role, string $email): User
    {
        return User::create([
            'email' => $email,
            'display_name' => $role->label(),
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    public function test_user_can_request_one_free_sa2_plus_mailbox(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'user@example.com');

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

    public function test_reserved_local_part_is_rejected(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'user2@example.com');

        $this->actingAs($user)
            ->post('/mail/domain-requests', ['local_part' => 'admin'])
            ->assertRedirect('/mail?tab=domain');

        $this->assertSame(0, MailDomainRequest::query()->count());
    }

    public function test_admin_can_approve_and_mark_provisioned_manually(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'member@example.com');
        $admin = $this->makeUser(UserRole::Admin, 'admin@example.com');

        $req = MailDomainRequest::create([
            'user_id' => $user->id,
            'local_part' => 'member',
            'domain' => 'sa2-plus.com',
            'status' => MailDomainRequest::STATUS_PENDING,
            'provisioning_mode' => 'manual',
        ]);

        $this->actingAs($admin)
            ->post("/admin/mail-requests/{$req->id}/approve")
            ->assertRedirect('/admin/mail-requests');

        $this->actingAs($admin)
            ->post("/admin/mail-requests/{$req->id}/provision")
            ->assertRedirect('/admin/mail-requests');

        $req->refresh();
        $this->assertSame(MailDomainRequest::STATUS_PROVISIONED, $req->status);
        $this->assertSame('manual', $req->provisioning_mode);
    }

    public function test_mail_page_is_available(): void
    {
        $user = $this->makeUser(UserRole::Light, 'light-mail@example.com');

        $this->actingAs($user)
            ->get('/mail?tab=domain')
            ->assertOk()
            ->assertSee('近日公開予定', false);
    }
}
