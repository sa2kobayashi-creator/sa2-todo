<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminBillingEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private function makeStaff(UserRole $role = UserRole::SuperAdmin): User
    {
        return User::create([
            'email' => 'staff-billing@example.com',
            'display_name' => 'Staff',
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    private function makeTarget(): User
    {
        return User::create([
            'email' => 'customer@example.com',
            'display_name' => 'Customer',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
        ]);
    }

    public function test_admin_can_set_billing_entitlements_on_user_edit(): void
    {
        $staff = $this->makeStaff();
        $target = $this->makeTarget();

        $this->actingAs($staff)
            ->from("/admin/users/{$target->id}/edit")
            ->post("/admin/users/{$target->id}/update", [
                'email' => $target->email,
                'displayName' => $target->display_name,
                'role' => UserRole::Standard->value,
                'menuFeaturesConfigured' => '1',
                'subscriptionStatus' => SubscriptionStatus::Active->value,
                'trialEndsAt' => '',
                'storageOverageActive' => '1',
                'mailboxAddonActive' => '1',
            ])
            ->assertRedirect("/admin/users/{$target->id}");

        $target->refresh();
        $this->assertSame(UserRole::Standard, $target->roleEnum());
        $this->assertSame(SubscriptionStatus::Active, $target->subscriptionStatusEnum());
        $this->assertTrue($target->storage_overage_active);
        $this->assertTrue($target->mailbox_addon_active);
    }

    public function test_user_detail_shows_billing_fields(): void
    {
        $staff = $this->makeStaff();
        $target = $this->makeTarget();
        $target->forceFill([
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => now()->addDays(7),
            'mailbox_addon_active' => true,
        ])->save();

        $this->actingAs($staff)
            ->get("/admin/users/{$target->id}")
            ->assertOk()
            ->assertSee(__('契約状態'), false)
            ->assertSee(__('お試し中'), false)
            ->assertSee(__('メールボックスオプション'), false);
    }

    public function test_photo_upload_respects_user_storage_overage_flag_when_paid_overage_enabled(): void
    {
        config([
            'photos.disk' => 'public',
            'photos.user_free_quota_bytes' => 1_000_000,
            'photos.standard_quota_bytes' => 1_000_000,
            'photos.block_uploads_over_free_quota' => true,
            'photos.paid_overage_enabled' => true,
        ]);
        \Illuminate\Support\Facades\Storage::fake('public');

        $user = User::create([
            'email' => 'overage-user@example.com',
            'display_name' => 'Overage',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
            'storage_overage_active' => true,
        ]);

        \App\Models\Photo::create([
            'user_id' => $user->id,
            'album_id' => null,
            'path' => 'photos/'.$user->id.'/existing.jpg',
            'thumb_path' => null,
            'original_name' => 'existing.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 1_500_000,
            'sort_order' => 0,
        ]);

        $this->actingAs($user)
            ->post('/photos', [
                'photos' => [\Illuminate\Http\UploadedFile::fake()->image('extra.jpg', 40, 40)],
            ])
            ->assertRedirect();

        $this->assertSame(2, \App\Models\Photo::query()->where('user_id', $user->id)->count());
    }
}
