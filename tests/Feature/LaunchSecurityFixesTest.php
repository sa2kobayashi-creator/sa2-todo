<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\GroupMessageAttachment;
use App\Models\Note;
use App\Models\NoteAttachment;
use App\Models\User;
use App\Support\SafeAttachmentResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LaunchSecurityFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_html_disguised_as_txt_is_served_as_download_not_inline(): void
    {
        Storage::fake('local');

        $user = User::create([
            'email' => 'xss-owner@example.com',
            'display_name' => 'Owner',
            'password' => Hash::make('password'),
            'role' => UserRole::Standard,
        ]);

        $note = Note::create([
            'user_id' => $user->id,
            'title' => 'n',
            'body' => 'b',
        ]);

        $path = 'notes/'.$user->id.'/'.$note->id.'/poc.txt';
        Storage::disk('local')->put($path, '<html><script>alert(1)</script></html>');

        $attachment = NoteAttachment::create([
            'note_id' => $note->id,
            'user_id' => $user->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'poc.txt',
            'mime' => 'text/html',
            'size_bytes' => 40,
        ]);

        $response = $this->actingAs($user)->get('/notes/attachments/'.$attachment->id.'/file');
        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_message_attachment_html_mime_is_forced_to_download(): void
    {
        Storage::fake('local');

        $owner = User::create([
            'email' => 'msg-owner@example.com',
            'display_name' => 'Owner',
            'password' => Hash::make('password'),
            'role' => UserRole::Standard,
        ]);
        $peer = User::create([
            'email' => 'msg-peer@example.com',
            'display_name' => 'Peer',
            'password' => Hash::make('password'),
            'role' => UserRole::Standard,
        ]);

        $group = Group::create([
            'name' => 'g',
            'owner_user_id' => $owner->id,
            'status' => GroupStatus::Approved,
        ]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $owner->id, 'role' => 'owner']);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $peer->id, 'role' => 'member']);

        $message = GroupMessage::create([
            'group_id' => $group->id,
            'user_id' => $owner->id,
            'body' => 'hi',
        ]);

        $path = 'messages/'.$group->id.'/'.$message->id.'/poc.txt';
        Storage::disk('local')->put($path, '<html><body>x</body></html>');

        $attachment = GroupMessageAttachment::create([
            'group_message_id' => $message->id,
            'user_id' => $owner->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'poc.txt',
            'mime' => 'text/html',
            'size_bytes' => 30,
        ]);

        $response = $this->actingAs($peer)->get('/messages/attachments/'.$attachment->id.'/file');
        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
    }

    public function test_safe_attachment_allows_images_inline(): void
    {
        $headers = SafeAttachmentResponse::headers('image/jpeg', 'a.jpg', false);
        $this->assertStringStartsWith('inline', $headers['Content-Disposition']);
        $this->assertSame('image/jpeg', $headers['Content-Type']);
    }

    public function test_tenant_admin_cannot_self_grant_billing_entitlements(): void
    {
        $super = User::create([
            'email' => 'ops-billing@example.com',
            'display_name' => 'Ops',
            'password' => Hash::make('password'),
            'role' => UserRole::SuperAdmin,
        ]);

        $this->actingAs($super)->post('/admin/tenants', [
            'name' => '課金改ざん家',
            'max_users' => 5,
            'allow_own_keys' => '1',
            'owner_display_name' => '代表',
            'owner_email' => 'tenant-bill@example.com',
            'owner_password' => 'password123',
        ])->assertRedirect('/admin/tenants');

        $owner = User::query()->where('email', 'tenant-bill@example.com')->firstOrFail();
        $this->assertNotNull($owner->tenant_id);

        $this->actingAs($owner)
            ->from("/admin/users/{$owner->id}/edit")
            ->post("/admin/users/{$owner->id}/update", [
                'email' => $owner->email,
                'displayName' => $owner->display_name,
                'role' => UserRole::Admin->value,
                'menuFeaturesConfigured' => '1',
                'subscriptionStatus' => SubscriptionStatus::Active->value,
                'storageOverageActive' => '1',
                'mailboxAddonActive' => '1',
            ])
            ->assertRedirect("/admin/users/{$owner->id}");

        $owner->refresh();
        $this->assertSame(SubscriptionStatus::None, $owner->subscriptionStatusEnum());
        $this->assertFalse((bool) $owner->storage_overage_active);
        $this->assertFalse((bool) $owner->mailbox_addon_active);

        $this->actingAs($owner)
            ->get("/admin/users/{$owner->id}/edit")
            ->assertOk()
            ->assertDontSee(__('契約・課金'), false);
    }

    public function test_locale_redirect_rejects_protocol_relative_url(): void
    {
        $this->from('/')
            ->post('/locale', [
                'locale' => 'en',
                'redirect' => '//evil.example/phish',
            ])
            ->assertRedirect('/dashboard');
    }
}
