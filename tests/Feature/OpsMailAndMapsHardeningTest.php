<?php

namespace Tests\Feature;

use App\Enums\RegistrationApplicationPlan;
use App\Enums\RegistrationApplicationStatus;
use App\Enums\UserRole;
use App\Mail\RegistrationApplicationApprovedMail;
use App\Models\RegistrationApplication;
use App\Models\User;
use App\Support\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class OpsMailAndMapsHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function purpose(): string
    {
        return 'TodoとPhotosを短期間試したいです。家族の予定共有も検討中です。';
    }

    public function test_register_deletes_user_when_welcome_mail_fails(): void
    {
        Registration::setInviteCode('ops-invite');
        Registration::setApplicationsOpen(true);

        $pending = Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')
            ->once()
            ->andThrow(new \RuntimeException('SMTP down'));
        Mail::shouldReceive('to')->once()->andReturn($pending);

        $this->from('/register')
            ->post('/register', [
                'email' => 'smtp-fail@example.com',
                'displayName' => 'Fail',
                'inviteCode' => 'ops-invite',
                'message' => $this->purpose(),
                'agreeTerms' => '1',
            ])
            ->assertRedirect('/register');

        $this->assertNull(User::query()->where('email', 'smtp-fail@example.com')->first());
    }

    public function test_light_apply_reverts_when_activation_mail_fails(): void
    {
        Registration::setApplicationsOpen(true);

        $pending = Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')
            ->once()
            ->andThrow(new \RuntimeException('SMTP down'));
        Mail::shouldReceive('to')->once()->andReturn($pending);

        $this->from('/apply')
            ->post('/apply', [
                'plan' => 'light',
                'display_name' => 'ライト失敗',
                'email' => 'light-mail-fail@example.com',
                'message' => $this->purpose(),
                'agreeTerms' => '1',
            ])
            ->assertRedirect('/apply');

        $row = RegistrationApplication::query()->where('email', 'light-mail-fail@example.com')->first();
        $this->assertNotNull($row);
        $this->assertSame(RegistrationApplicationStatus::Pending, $row->status);
        $this->assertNull($row->approval_token_hash);
    }

    public function test_admin_can_resend_activation_mail(): void
    {
        Mail::fake();
        $owner = User::create([
            'email' => 'ops-resend@example.com',
            'display_name' => 'Ops',
            'password' => Hash::make('password'),
            'role' => UserRole::SuperAdmin,
        ]);

        $application = RegistrationApplication::create([
            'plan' => RegistrationApplicationPlan::Standard,
            'email' => 'awaiting@example.com',
            'display_name' => '待ち',
            'message' => $this->purpose(),
            'status' => RegistrationApplicationStatus::Pending,
        ]);

        $this->actingAs($owner)
            ->post('/admin/applications/'.$application->id.'/approve')
            ->assertRedirect('/admin/applications');

        Mail::assertSent(RegistrationApplicationApprovedMail::class);
        $application->refresh();
        $this->assertTrue($application->isAwaitingActivation());

        Mail::fake();
        $this->actingAs($owner)
            ->post('/admin/applications/'.$application->id.'/resend')
            ->assertRedirect('/admin/applications');

        Mail::assertSent(RegistrationApplicationApprovedMail::class);
        $this->assertTrue($application->fresh()->isAwaitingActivation());
    }

    public function test_google_maps_settings_show_referrer_checklist_and_can_confirm(): void
    {
        config(['app.url' => 'https://sa2-plus.com']);
        $owner = User::create([
            'email' => 'maps-ops@example.com',
            'display_name' => 'Ops',
            'password' => Hash::make('password'),
            'role' => UserRole::SuperAdmin,
        ]);

        $this->actingAs($owner)
            ->get('/settings?section=enhance')
            ->assertOk()
            ->assertSee(__('必須: HTTP リファラ制限'), false)
            ->assertSee('https://sa2-plus.com/*', false)
            ->assertSee(__('HTTP リファラ制限を Cloud コンソールで設定済み'), false);

        $this->actingAs($owner)
            ->post('/settings/api/google-maps', [
                'enabled' => '1',
                'api_key' => 'AIzaSyDummyKeyForTest123456',
                'referrer_restriction_confirmed' => '1',
            ])
            ->assertRedirect('/settings?section=enhance#google-maps-api-settings');

        $this->actingAs($owner)
            ->get('/settings?section=enhance')
            ->assertOk()
            ->assertSee(__('リファラ制限の確認済み'), false);
    }
}
