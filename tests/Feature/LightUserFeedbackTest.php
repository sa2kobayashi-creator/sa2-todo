<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\LightUserFeedbackMail;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class LightUserFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $google = Mockery::mock(GoogleCalendarService::class);
        $google->shouldReceive('connectionFor')->andReturn(null)->byDefault();
        $google->shouldReceive('listEventsAsTodos')->andReturn([])->byDefault();
        $google->shouldReceive('mergeEventsIntoTodos')->andReturnUsing(fn ($user, $todos) => $todos)->byDefault();
        $this->app->instance(GoogleCalendarService::class, $google);
    }

    public function test_light_user_sees_feedback_button_on_dashboard(): void
    {
        $user = User::create([
            'email' => 'light-feedback@example.com',
            'display_name' => 'Light',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee(__('ご使用後の意見はこちら'), false)
            ->assertSee('id="dash-light-feedback-modal"', false);
    }

    public function test_standard_user_does_not_see_feedback_button(): void
    {
        $user = User::create([
            'email' => 'standard-nofeedback@example.com',
            'display_name' => 'Standard',
            'password' => Hash::make('password'),
            'role' => UserRole::Standard,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('id="dash-light-feedback-modal"', false);
    }

    public function test_light_user_can_send_feedback_to_info_mailbox(): void
    {
        Mail::fake();
        config(['registration.light_feedback_to' => 'info@sa2-plus.com']);

        $user = User::create([
            'email' => 'light-send@example.com',
            'display_name' => '送信者',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
        ]);

        $this->actingAs($user)
            ->post('/dashboard/light-feedback', [
                'body' => '翻訳の音声入力が便利でした。',
            ])
            ->assertRedirect('/dashboard');

        Mail::assertSent(LightUserFeedbackMail::class, function (LightUserFeedbackMail $mail) {
            return $mail->hasTo('info@sa2-plus.com')
                && $mail->envelope()->subject === 'ライトユーザーのご意見'
                && $mail->bodyText === '翻訳の音声入力が便利でした。'
                && $mail->senderEmail === 'light-send@example.com';
        });
    }

    public function test_standard_user_cannot_send_light_feedback(): void
    {
        Mail::fake();

        $user = User::create([
            'email' => 'standard-send@example.com',
            'display_name' => 'Standard',
            'password' => Hash::make('password'),
            'role' => UserRole::Standard,
        ]);

        $this->actingAs($user)
            ->post('/dashboard/light-feedback', [
                'body' => 'should not send',
            ])
            ->assertForbidden();

        Mail::assertNothingSent();
    }
}
