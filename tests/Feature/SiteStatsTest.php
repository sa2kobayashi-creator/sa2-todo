<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\SiteStatDaily;
use App\Models\User;
use App\Support\SiteStatEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SiteStatsTest extends TestCase
{
    use RefreshDatabase;

    private function purpose(): string
    {
        return 'TodoとPhotosを短期間試したいです。家族の予定共有も検討中です。';
    }

    private function makeSuper(): User
    {
        return User::create([
            'email' => 'stats-ops@example.com',
            'display_name' => 'Ops',
            'password' => Hash::make('password'),
            'role' => UserRole::SuperAdmin,
        ]);
    }

    public function test_top_page_increments_view_stats(): void
    {
        $this->get('/')->assertOk();

        $row = SiteStatDaily::query()
            ->where('event_key', SiteStatEvent::TOP_VIEW)
            ->whereDate('stat_date', now()->toDateString())
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->count);
    }

    public function test_repeat_views_accumulate_on_the_same_row(): void
    {
        $this->get('/')->assertOk();
        $this->get('/')->assertOk();
        $this->get('/')->assertOk();

        $rows = SiteStatDaily::query()
            ->where('event_key', SiteStatEvent::TOP_VIEW)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame(3, (int) $rows->first()->count);
    }

    public function test_cta_hit_endpoint_accepts_allowed_events(): void
    {
        $this->post('/stats/hit', ['event' => SiteStatEvent::CTA_PLAN_STANDARD])
            ->assertOk();

        $this->assertDatabaseHas('site_stats_daily', [
            'event_key' => SiteStatEvent::CTA_PLAN_STANDARD,
        ]);

        $this->post('/stats/hit', ['event' => 'hack.drop.table'])
            ->assertStatus(422);
    }

    public function test_apply_and_login_are_counted(): void
    {
        Mail::fake();
        $this->makeSuper();

        $this->get('/apply?plan=standard')->assertOk();
        $this->assertDatabaseHas('site_stats_daily', ['event_key' => SiteStatEvent::APPLY_VIEW_STANDARD]);

        $this->post('/apply', [
            'plan' => 'standard',
            'display_name' => '統計',
            'email' => 'stats-apply@example.com',
            'message' => $this->purpose(),
            'agreeTerms' => '1',
        ])->assertRedirect('/apply');

        $this->assertDatabaseHas('site_stats_daily', ['event_key' => SiteStatEvent::APPLY_SUBMIT_STANDARD]);

        $user = User::create([
            'email' => 'stats-login@example.com',
            'display_name' => 'Login',
            'password' => Hash::make('password123'),
            'role' => UserRole::Light,
        ]);

        $this->post('/login', [
            'email' => 'stats-login@example.com',
            'password' => 'password123',
        ])->assertRedirect();

        $this->assertDatabaseHas('site_stats_daily', ['event_key' => SiteStatEvent::LOGIN]);
        $this->assertNotNull($user->fresh());
    }

    public function test_super_admin_can_open_stats_settings(): void
    {
        $super = $this->makeSuper();

        $this->actingAs($super)
            ->get('/settings?section=stats')
            ->assertOk()
            ->assertSee(__('アクセス統計'), false)
            ->assertSee(__('TOPページ'), false)
            ->assertSee(__('プラン・導線クリック'), false);
    }

    public function test_non_super_admin_cannot_open_stats_settings(): void
    {
        $admin = User::create([
            'email' => 'stats-admin@example.com',
            'display_name' => 'Admin',
            'password' => Hash::make('password'),
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)
            ->get('/settings?section=stats')
            ->assertOk()
            ->assertDontSee(__('プラン・導線クリック'), false);
    }
}
