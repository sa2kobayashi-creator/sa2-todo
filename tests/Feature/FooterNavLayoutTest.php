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
            ->assertSee('スマートフォン下部は1行5件、最大15件です', false);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('mobile-nav-grid', false)
            ->assertSee('data-expandable="1"', false)
            ->assertSee('data-expanded-rows="3"', false)
            ->assertSee('mobile-nav-handle', false);

        $css = file_get_contents(public_path('app.css'));
        $this->assertNotFalse($css);
        $this->assertStringContainsString('grid-template-columns: repeat(5, minmax(0, 1fr))', $css);
        $this->assertStringContainsString('.transit-time-types', $css);
    }
}
