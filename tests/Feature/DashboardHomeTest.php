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
    }
}
