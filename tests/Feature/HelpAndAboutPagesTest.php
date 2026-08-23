<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HelpAndAboutPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_help_and_about_require_auth_and_render_for_members(): void
    {
        $this->get('/help')->assertRedirect('/login');
        $this->get('/about')->assertRedirect('/login');

        $user = User::create([
            'email' => 'help-user@example.com',
            'display_name' => 'Help User',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
        ]);

        $this->actingAs($user)->get('/help')
            ->assertOk()
            ->assertSee(__('ヘルプ'), false);

        $this->actingAs($user)->get('/about')
            ->assertOk()
            ->assertSee(__('Sa2 Plus について'), false)
            ->assertSee('Laravel', false);

        $this->actingAs($user)->get('/help')
            ->assertOk()
            ->assertDontSee('href="/help/overview"', false);
    }

    public function test_admin_help_includes_overview_and_usage_guide(): void
    {
        $admin = User::create([
            'email' => 'admin-help-docs@example.com',
            'display_name' => 'Admin',
            'password' => Hash::make('password'),
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)->get('/help')
            ->assertOk()
            ->assertSee('通常のヘルプ', false)
            ->assertSee('このアプリの概要', false)
            ->assertSee('このアプリの使用方法', false);

        $this->actingAs($admin)->get('/help/overview')
            ->assertOk()
            ->assertSee('このアプリの概要', false)
            ->assertSee('管理者（あなた）', false);

        $this->actingAs($admin)->get('/help/guide')
            ->assertOk()
            ->assertSee('このアプリの使用方法', false)
            ->assertSee('最初にやること', false);
    }

    public function test_standard_user_cannot_open_admin_help_guides(): void
    {
        $user = User::create([
            'email' => 'standard-help-docs@example.com',
            'display_name' => 'Standard',
            'password' => Hash::make('password'),
            'role' => UserRole::Standard,
        ]);

        $this->actingAs($user)->get('/help/overview')->assertForbidden();
        $this->actingAs($user)->get('/help/guide')->assertForbidden();
    }

    public function test_admin_can_send_an_inquiry_to_the_operator(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $operator = User::create([
            'email' => 'operator@example.com',
            'display_name' => 'Operator',
            'password' => Hash::make('password'),
            'role' => UserRole::SuperAdmin,
        ]);
        $admin = User::create([
            'email' => 'admin-contact@example.com',
            'display_name' => 'Admin',
            'password' => Hash::make('password'),
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)->get('/contact')
            ->assertOk()
            ->assertSee('お問い合わせ', false)
            ->assertSee('href="/help"', false);

        $this->actingAs($admin)->post('/contact', [
            'subject' => 'ストレージについて',
            'body' => '容量の増やし方を教えてください。',
        ])->assertRedirect('/contact');

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\OperatorInquiryMail::class, function (\App\Mail\OperatorInquiryMail $mail) use ($operator, $admin) {
            return $mail->hasTo($operator->email)
                && $mail->senderEmail === $admin->email
                && $mail->subjectLine === 'ストレージについて';
        });
    }

    public function test_standard_user_cannot_open_contact_page(): void
    {
        $user = User::create([
            'email' => 'standard-contact@example.com',
            'display_name' => 'Standard',
            'password' => Hash::make('password'),
            'role' => UserRole::Standard,
        ]);

        $this->actingAs($user)->get('/contact')->assertForbidden();
        $this->actingAs($user)->post('/contact', [
            'subject' => 'hack',
            'body' => 'nope',
        ])->assertForbidden();
    }

    public function test_register_page_shows_disabled_agree_checkbox_until_docs_read(): void
    {
        \App\Support\Registration::setInviteCode('invite-test');

        $html = $this->get('/register')->assertOk()->getContent();
        $this->assertStringContainsString('id="agree-terms"', $html);
        $this->assertMatchesRegularExpression('/id="agree-terms"[^>]*\bdisabled\b/', $html);
        $this->assertStringContainsString('agree-terms-row', $html);
    }
}
