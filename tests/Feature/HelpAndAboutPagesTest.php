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
