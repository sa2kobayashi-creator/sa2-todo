<?php

namespace Tests\Feature;

use App\Enums\RegistrationApplicationPlan;
use App\Enums\RegistrationApplicationStatus;
use App\Enums\UserRole;
use App\Mail\RegistrationApplicationApprovedMail;
use App\Mail\RegistrationApplicationReceivedAdminMail;
use App\Models\RegistrationApplication;
use App\Models\User;
use App\Support\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegistrationApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Registration::setApplicationsOpen(true);
    }

    private function purpose(): string
    {
        return 'TodoとPhotosを短期間試したいです。家族の予定共有も検討中です。';
    }

    private function makeOwner(): User
    {
        return User::create([
            'email' => 'owner-apply@example.com',
            'display_name' => 'Owner',
            'password' => Hash::make('password'),
            'role' => UserRole::SuperAdmin,
        ]);
    }

    public function test_guest_can_open_the_apply_page(): void
    {
        $this->get('/apply')
            ->assertOk()
            ->assertSee(__('利用申請'), false)
            ->assertSee('name="plan"', false);
    }

    public function test_apply_page_redirects_home_when_applications_are_closed(): void
    {
        Registration::setApplicationsOpen(false);

        $this->get('/apply')->assertRedirect('/');
        $this->post('/apply', [
            'plan' => 'light',
            'display_name' => '閉',
            'email' => 'closed-apply@example.com',
            'message' => $this->purpose(),
            'agreeTerms' => '1',
        ])->assertRedirect('/');
    }

    public function test_light_application_is_auto_approved_without_admin_mail(): void
    {
        Mail::fake();
        $this->makeOwner();

        $this->post('/apply', [
            'plan' => 'light',
            'display_name' => '申請太郎',
            'email' => 'apply-light@example.com',
            'message' => $this->purpose(),
            'agreeTerms' => '1',
        ])->assertRedirect('/apply');

        $this->assertDatabaseHas('registration_applications', [
            'email' => 'apply-light@example.com',
            'plan' => 'light',
            'status' => 'approved',
        ]);

        Mail::assertSent(RegistrationApplicationApprovedMail::class);
        Mail::assertNotSent(RegistrationApplicationReceivedAdminMail::class);
    }

    public function test_standard_application_notifies_admins_and_stays_pending(): void
    {
        Mail::fake();
        $this->makeOwner();

        $this->post('/apply', [
            'plan' => 'standard',
            'display_name' => '本利用希望',
            'email' => 'apply-standard@example.com',
            'message' => $this->purpose(),
            'agreeTerms' => '1',
        ])->assertRedirect('/apply');

        $this->assertDatabaseHas('registration_applications', [
            'email' => 'apply-standard@example.com',
            'plan' => 'standard',
            'status' => 'pending',
        ]);

        Mail::assertSent(RegistrationApplicationReceivedAdminMail::class);
        Mail::assertNotSent(RegistrationApplicationApprovedMail::class);
    }

    public function test_short_purpose_is_rejected(): void
    {
        Mail::fake();

        $this->post('/apply', [
            'plan' => 'light',
            'display_name' => '短い',
            'email' => 'short-purpose@example.com',
            'message' => '試したい',
            'agreeTerms' => '1',
        ])->assertRedirect('/apply?plan=light')
            ->assertSessionHasErrors('message');

        $this->assertSame(0, RegistrationApplication::query()->count());
        Mail::assertNothingSent();
    }

    public function test_disposable_email_is_rejected(): void
    {
        Mail::fake();

        $this->post('/apply', [
            'plan' => 'light',
            'display_name' => '捨てアド',
            'email' => 'spam@mailinator.com',
            'message' => $this->purpose(),
            'agreeTerms' => '1',
        ])->assertRedirect('/apply');

        $this->assertSame(0, RegistrationApplication::query()->count());
        Mail::assertNothingSent();
    }

    public function test_light_weekly_cap_blocks_new_applications(): void
    {
        Mail::fake();
        config(['registration.light_weekly_cap' => 1]);

        User::create([
            'email' => 'existing-light@example.com',
            'display_name' => 'Existing Light',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
            'created_at' => now()->subDay(),
        ]);

        $this->post('/apply', [
            'plan' => 'light',
            'display_name' => 'あふれ',
            'email' => 'overflow-light@example.com',
            'message' => $this->purpose(),
            'agreeTerms' => '1',
        ])->assertRedirect('/apply');

        $this->assertSame(0, RegistrationApplication::query()->where('email', 'overflow-light@example.com')->count());
        Mail::assertNothingSent();
    }

    public function test_tenant_application_requires_organization_name(): void
    {
        $this->post('/apply', [
            'plan' => 'tenant',
            'display_name' => '家族代表',
            'email' => 'tenant-apply@example.com',
            'message' => $this->purpose(),
            'agreeTerms' => '1',
        ])->assertRedirect('/apply?plan=tenant')
            ->assertSessionHasErrors('organization_name');
    }

    public function test_owner_can_approve_and_applicant_activates_with_password(): void
    {
        Mail::fake();
        $owner = $this->makeOwner();

        $application = RegistrationApplication::create([
            'plan' => RegistrationApplicationPlan::Standard,
            'email' => 'activate-me@example.com',
            'display_name' => 'スタンダード希望',
            'message' => $this->purpose(),
            'status' => RegistrationApplicationStatus::Pending,
        ]);

        $this->actingAs($owner)
            ->post('/admin/applications/'.$application->id.'/approve')
            ->assertRedirect('/admin/applications');

        $application->refresh();
        $this->assertSame(RegistrationApplicationStatus::Approved, $application->status);

        $plain = null;
        Mail::assertSent(RegistrationApplicationApprovedMail::class, function (RegistrationApplicationApprovedMail $mail) use (&$plain) {
            $plain = basename(parse_url($mail->activateUrl, PHP_URL_PATH));

            return $mail->isStandard === true;
        });

        $this->assertNotEmpty($plain);

        auth()->logout();

        $this->get('/apply/activate/'.$plain)
            ->assertOk()
            ->assertSee('activate-me@example.com', false);

        $this->post('/apply/activate/'.$plain, [
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect('/mypage/plan');

        $user = User::query()->where('email', 'activate-me@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame(UserRole::Light, $user->roleEnum());
        $this->assertFalse((bool) $user->must_change_password);
        $this->assertSame(RegistrationApplicationStatus::Completed, $application->fresh()->status);
        $this->assertAuthenticatedAs($user);
    }

    public function test_expired_or_invalid_token_cannot_activate(): void
    {
        $this->get('/apply/activate/notarealtoken')
            ->assertOk()
            ->assertSee(__('登録リンクが無効です'), false);
    }

    public function test_existing_email_does_not_create_a_second_account_hint(): void
    {
        Mail::fake();
        User::create([
            'email' => 'taken@example.com',
            'display_name' => 'Existing',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
        ]);

        $this->post('/apply', [
            'plan' => 'light',
            'display_name' => '誰か',
            'email' => 'taken@example.com',
            'message' => $this->purpose(),
            'agreeTerms' => '1',
        ])->assertRedirect('/apply')
            ->assertSessionHas('notice', __('条件を確認しました。登録用のメールをお送りできる場合は、まもなく届きます。'));

        $this->assertSame(0, RegistrationApplication::query()->where('email', 'taken@example.com')->count());
        Mail::assertNothingSent();
    }

    public function test_repeat_light_apply_reuses_awaiting_row_instead_of_multiplying(): void
    {
        Mail::fake();
        config(['registration.light_weekly_cap' => 50]);

        $payload = [
            'plan' => 'light',
            'display_name' => '再送',
            'email' => 'reuse-light@example.com',
            'message' => $this->purpose(),
            'agreeTerms' => '1',
        ];

        $this->post('/apply', $payload)->assertRedirect('/apply');
        $this->post('/apply', $payload)->assertRedirect('/apply');
        $this->post('/apply', $payload)->assertRedirect('/apply');

        $this->assertSame(1, RegistrationApplication::query()->where('email', 'reuse-light@example.com')->count());
        Mail::assertSent(RegistrationApplicationApprovedMail::class, 3);
    }
}
