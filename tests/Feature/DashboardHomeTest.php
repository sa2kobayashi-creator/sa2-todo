<?php

namespace Tests\Feature;

use App\Models\Todo;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

class DashboardHomeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-11 09:00:00', 'Asia/Tokyo'));

        $this->user = User::create([
            'email' => 'dashpage@example.com',
            'display_name' => '小林',
            'password' => Hash::make('password'),
            'role' => 'standard',
        ]);

        $google = Mockery::mock(GoogleCalendarService::class);
        $google->shouldReceive('connectionFor')->andReturn(null)->byDefault();
        $google->shouldReceive('listEventsAsTodos')->andReturn([])->byDefault();
        $this->app->instance(GoogleCalendarService::class, $google);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_renders_today_hub_sections(): void
    {
        Todo::create([
            'user_id' => $this->user->id,
            'title' => '見積書を送る',
            'completed' => false,
            'start_date' => '2026-08-11',
            'end_date' => '2026-08-11',
            'start_time' => '14:00',
            'importance' => 'medium',
            'category' => 'task',
        ]);

        $response = $this->actingAs($this->user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('dash-home', false);
        $response->assertSee('次にやること', false);
        $response->assertSee('今日の予定', false);
        $response->assertSee('見積書を送る', false);
        $response->assertSee('小林', false);
        $response->assertSee('dash-calendar-accordion', false);
        $response->assertSee('メモ', false);
        $response->assertSee('路線検索', false);
    }

    public function test_dashboard_shows_google_connect_cta_when_disconnected(): void
    {
        $response = $this->actingAs($this->user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Googleカレンダーは未連携です', false);
        $response->assertSee('Googleカレンダーを連携', false);
        $response->assertDontSee('⚠', false);
    }

    public function test_dashboard_google_calendar_link_opens_calendar_when_connected(): void
    {
        $google = Mockery::mock(GoogleCalendarService::class);
        $google->shouldReceive('connectionFor')->andReturn(new \App\Models\GoogleCalendarConnection([
            'user_id' => $this->user->id,
            'google_user_id' => 'g-1',
            'google_email' => 'g@example.com',
        ]));
        $google->shouldReceive('listEventsAsTodos')->andReturn([]);
        $this->app->instance(GoogleCalendarService::class, $google);

        $response = $this->actingAs($this->user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('https://calendar.google.com/calendar/r/day', false);
        $response->assertDontSee('href="/settings?section=integration#google-calendar">Google Calendar', false);
    }

    public function test_ai_usage_panel_is_hidden_from_standard_users(): void
    {
        $this->actingAs($this->user)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('AI使用料・翻訳使用料', false)
            ->assertDontSee('dash-ai-usage', false);
    }

    public function test_ai_usage_panel_is_visible_to_admins(): void
    {
        $admin = User::create([
            'email' => 'dashadmin@example.com',
            'display_name' => '管理者',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('AI使用料・翻訳使用料', false)
            ->assertSee('dash-ai-usage', false);
    }
}
