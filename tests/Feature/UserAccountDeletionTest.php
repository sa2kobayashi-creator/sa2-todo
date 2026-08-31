<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MusicTrack;
use App\Models\Note;
use App\Models\Photo;
use App\Models\Todo;
use App\Models\User;
use App\Services\UserAccountDeletionService;
use App\Services\StripeBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserAccountDeletionTest extends TestCase
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

    public function test_user_can_delete_own_account_with_password_confirmation(): void
    {
        Storage::fake('public');
        $user = $this->makeUser(UserRole::Standard, 'leave@example.com');
        $this->makeUser(UserRole::SuperAdmin, 'keep-admin@example.com');

        Todo::create([
            'user_id' => $user->id,
            'title' => 'gone',
            'completed' => false,
        ]);
        Note::create([
            'user_id' => $user->id,
            'title' => 'note',
            'body' => 'body',
            'category' => 'personal',
        ]);

        $response = $this->actingAs($user)->post('/mypage/delete', [
            'password' => 'password',
            'confirm' => '退会',
        ]);

        $response->assertRedirect();
        $this->assertStringStartsWith(url('/login'), $response->headers->get('Location'));
        $this->assertGuest();

        $this->app->terminate();

        $this->assertDatabaseMissing('users', ['email' => 'leave@example.com']);
        $this->assertDatabaseMissing('todos', ['title' => 'gone']);
        $this->assertDatabaseMissing('notes', ['title' => 'note']);
        $this->assertDatabaseHas('users', ['email' => 'keep-admin@example.com']);
    }

    public function test_account_deletion_rejects_wrong_password(): void
    {
        $user = $this->makeUser(UserRole::Light, 'wrong-pass@example.com');

        $this->actingAs($user)->post('/mypage/delete', [
            'password' => 'nope',
            'confirm' => '退会',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'wrong-pass@example.com']);
    }

    public function test_admin_delete_uses_account_deletion_service(): void
    {
        Storage::fake(config('photos.disk', 'public'));
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin-del@example.com');
        $target = $this->makeUser(UserRole::Light, 'target-del@example.com');

        $disk = config('photos.disk', 'public');
        $path = 'photos/'.$target->id.'/sample.jpg';
        Storage::disk($disk)->put($path, 'fake-image');

        Photo::create([
            'user_id' => $target->id,
            'path' => $path,
            'original_name' => 'sample.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 10,
        ]);

        MusicTrack::create([
            'user_id' => $target->id,
            'title' => 'song',
            'path' => 'music/'.$target->id.'/a.mp3',
            'original_name' => 'a.mp3',
            'mime' => 'audio/mpeg',
            'size_bytes' => 10,
        ]);
        Storage::disk(config('music.disk', $disk))->put('music/'.$target->id.'/a.mp3', 'audio');

        $this->actingAs($admin)->post("/admin/users/{$target->id}/delete")->assertRedirect();

        $this->app->terminate();

        $this->assertDatabaseMissing('users', ['email' => 'target-del@example.com']);
        $this->assertDatabaseMissing('photos', ['path' => $path]);
        $this->assertFalse(Storage::disk($disk)->exists($path));
    }

    public function test_deletion_service_removes_null_on_delete_notes(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'svc-del@example.com');
        Note::create([
            'user_id' => $user->id,
            'title' => 'keep-check',
            'body' => 'x',
            'category' => 'personal',
        ]);

        app(UserAccountDeletionService::class)->delete($user);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('notes', ['title' => 'keep-check']);
    }

    public function test_account_deletion_cancels_stripe_before_removing_user(): void
    {
        $user = $this->makeUser(UserRole::Light, 'stripe-leave@example.com');
        $user->forceFill(['stripe_id' => 'cus_delete_test'])->save();

        $billing = \Mockery::mock(StripeBillingService::class)->makePartial();
        $billing->shouldReceive('cancelAllSubscriptionsForDeletion')
            ->once()
            ->with(\Mockery::on(fn ($u) => (int) $u->id === (int) $user->id));
        $this->app->instance(StripeBillingService::class, $billing);

        app(UserAccountDeletionService::class)->delete($user->fresh());

        $this->assertDatabaseMissing('users', ['email' => 'stripe-leave@example.com']);
    }

    public function test_mypage_delete_aborts_when_stripe_cancel_fails(): void
    {
        $this->makeUser(UserRole::SuperAdmin, 'keep-sa@example.com');
        $user = $this->makeUser(UserRole::Light, 'stripe-fail-leave@example.com');
        $user->forceFill(['stripe_id' => 'cus_fail_test'])->save();

        $billing = \Mockery::mock(StripeBillingService::class)->makePartial();
        $billing->shouldReceive('cancelAllSubscriptionsForDeletion')
            ->once()
            ->andThrow(new \RuntimeException('有料契約の解約に失敗しました。'));
        $this->app->instance(StripeBillingService::class, $billing);

        $this->actingAs($user)->post('/mypage/delete', [
            'password' => 'password',
            'confirm' => '退会',
        ])->assertRedirect();

        $this->assertSame('有料契約の解約に失敗しました。', session('error'));
        $this->assertDatabaseHas('users', ['email' => 'stripe-fail-leave@example.com']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_mypage_delete_aborts_when_entitled_without_stripe_customer(): void
    {
        config(['cashier.secret' => 'sk_test_dummy']);
        $this->makeUser(UserRole::SuperAdmin, 'keep-sa2@example.com');
        $user = $this->makeUser(UserRole::Standard, 'entitled-no-cus@example.com');
        $user->forceFill([
            'subscription_status' => \App\Enums\SubscriptionStatus::Trial,
            'trial_ends_at' => now()->addDays(7),
            'stripe_id' => null,
        ])->save();

        $this->actingAs($user)->post('/mypage/delete', [
            'password' => 'password',
            'confirm' => '退会',
        ])->assertRedirect();

        $this->assertNotEmpty(session('error'));
        $this->assertDatabaseHas('users', ['email' => 'entitled-no-cus@example.com']);
        $this->assertAuthenticatedAs($user);
    }
}
