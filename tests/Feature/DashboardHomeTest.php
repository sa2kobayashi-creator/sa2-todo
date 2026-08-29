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
        $google->shouldReceive('mergeEventsIntoTodos')->andReturnUsing(fn ($user, $todos) => $todos)->byDefault();
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

    public function test_personal_mode_links_to_app_calendar_and_hides_google_connect(): void
    {
        $response = $this->actingAs($this->user)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('Googleカレンダーは未連携です', false);
        $response->assertSee('/todos?view=day&amp;date=2026-08-11', false);
        $response->assertSee('今日のTodo', false);
        $response->assertDontSee('⚠', false);
    }

    public function test_work_mode_shows_google_connect_cta_when_disconnected(): void
    {
        $this->user->app_context = 'work';
        $this->user->save();

        $response = $this->actingAs($this->user)
            ->withSession(['app_context' => 'work'])
            ->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('仕事モード: プライベートの ToDo／メモは表示されません。');
        $response->assertDontSee('カレンダー選択・取込');
        $response->assertSee('Googleカレンダーは未連携です', false);
        $response->assertSee('Googleカレンダーを連携', false);
    }

    public function test_work_mode_google_calendar_link_uses_connected_account(): void
    {
        $this->user->app_context = 'work';
        $this->user->save();

        $google = Mockery::mock(GoogleCalendarService::class);
        $google->shouldReceive('connectionFor')->andReturn(new \App\Models\GoogleCalendarConnection([
            'user_id' => $this->user->id,
            'google_user_id' => 'g-1',
            'google_email' => 'work-cal@example.com',
        ]));
        $google->shouldReceive('listEventsAsTodos')->andReturn([]);
        $google->shouldReceive('mergeEventsIntoTodos')->andReturnUsing(fn ($user, $todos) => $todos);
        $this->app->instance(GoogleCalendarService::class, $google);

        $response = $this->actingAs($this->user)
            ->withSession(['app_context' => 'work'])
            ->get('/dashboard');

        $response->assertOk();
        $response->assertSee('AccountChooser', false);
        $response->assertSee('work-cal%40example.com', false);
        $response->assertDontSee('href="/mypage#google-calendar">Google Calendar', false);
    }

    public function test_ai_usage_panel_is_removed_from_dashboard(): void
    {
        $admin = User::create([
            'email' => 'dashadmin@example.com',
            'display_name' => '管理者',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        $this->actingAs($this->user)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('dash-ai-usage', false);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('dash-ai-usage', false)
            ->assertDontSee('AI使用料・翻訳使用料', false);
    }
}
