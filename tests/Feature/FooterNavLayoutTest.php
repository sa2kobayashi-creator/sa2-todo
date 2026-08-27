<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\FooterNav;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FooterNavLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'email' => 'footer-nav-layout@example.com',
            'display_name' => 'Footer Nav User',
            'password' => Hash::make('password'),
            'role' => UserRole::SuperAdmin,
        ]);
    }

    public function test_footer_bar_keeps_fifteen_items_and_uses_three_rows_from_eleven(): void
    {
        $this->assertSame(15, FooterNav::MAX_FOOTER);
        $this->assertSame(5, FooterNav::FOOTER_PER_ROW);
        $this->assertSame(1, FooterNav::footerExpandedRows(5));
        $this->assertSame(2, FooterNav::footerExpandedRows(6));
        $this->assertSame(2, FooterNav::footerExpandedRows(10));
        $this->assertSame(3, FooterNav::footerExpandedRows(11));
        $this->assertSame(3, FooterNav::footerExpandedRows(15));

        $user = $this->user();
        $all = FooterNav::keys();
        $this->assertSame($all, FooterNav::normalizeFooterKeys($all, $user));
        $this->assertCount(count($all), FooterNav::normalizeFooterKeys([...$all, 'dashboard'], $user));
    }

    public function test_settings_and_bottom_bar_describe_swipe_rows(): void
    {
        $user = $this->user();
        $user->footer_nav = array_slice(FooterNav::keys(), 0, 12);
        $user->save();

        $this->actingAs($user)->get('/settings?section=nav')
            ->assertOk()
            ->assertSee('スマートフォン下部は1行5件、最大15件です', false)
            ->assertSee('長押しすると全メニューが表示されます', false)
            ->assertSee('さらに長押しすると並べ替えできます', false)
            ->assertDontSee('バーを上にスワイプすると', false);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('mobile-nav-grid', false)
            ->assertSee('data-expandable="1"', false)
            ->assertSee('data-expanded-rows="3"', false)
            ->assertDontSee('mobile-nav-handle', false)
            ->assertSee('mobile-nav-done', false)
            ->assertSee('data-nav-key=', false)
            ->assertSee('mobile-nav.js', false);

        $css = file_get_contents(public_path('app.css'));
        $this->assertNotFalse($css);
        $this->assertStringContainsString('--mobile-nav-handle-height: 0px', $css);
        $this->assertStringNotContainsString('--mobile-nav-handle-height: 44px', $css);
        $this->assertStringNotContainsString('.mobile-nav-handle-bar', $css);
        $this->assertStringContainsString('.mobile-bottom-nav button.mobile-nav-done', $css);
        $this->assertStringContainsString('min-height: 28px', $css);
        $this->assertStringContainsString('.transit-time-types', $css);
    }

    public function test_signed_in_user_can_reorder_footer_icons(): void
    {
        $user = User::create([
            'email' => 'footer-nav-light@example.com',
            'display_name' => 'Light Footer',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
        ]);
        $current = FooterNav::normalizeFooterKeys(null, $user);
        $this->assertNotEmpty($current);
        $reordered = array_values(array_reverse($current));

        $this->actingAs($user)
            ->postJson('/mypage/footer-nav', ['footer_nav' => $reordered])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('footer_nav', $reordered);

        $user->refresh();
        $this->assertSame($reordered, $user->footer_nav);

        $this->actingAs($user)
            ->postJson('/mypage/footer-nav', ['footer_nav' => ['dashboard']])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_guest_cannot_reorder_footer_icons(): void
    {
        $this->postJson('/mypage/footer-nav', ['footer_nav' => ['todos', 'dashboard']])
            ->assertUnauthorized();
    }
}
