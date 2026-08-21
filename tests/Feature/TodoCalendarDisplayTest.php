<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TodoCalendarDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'email' => 'todo-cal@example.com',
            'display_name' => 'Todo Cal',
            'password' => Hash::make('password'),
            'role' => UserRole::Admin,
        ]);
    }

    public function test_todos_default_display_is_calendar(): void
    {
        $user = $this->makeUser();
        Todo::create([
            'user_id' => $user->id,
            'title' => '月次レビュー',
            'completed' => false,
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
            'importance' => 'medium',
            'category' => 'task',
            'reminders' => [],
            'notified_at' => [],
        ]);

        $response = $this->actingAs($user)->get('/todos');
        $response->assertOk();
        $response->assertSee('todos-calendar-panel', false);
        $response->assertSee('id="todo-modal"', false);
        $response->assertSee('月次レビュー');
        $response->assertSee('view=day&amp;date='.now()->format('Y-m-d'), false);
        $response->assertDontSee('day-event-count', false);
        $response->assertDontSee('todo-table', false);
    }

    public function test_todos_list_display_via_query(): void
    {
        $user = $this->makeUser();
        Todo::create([
            'user_id' => $user->id,
            'title' => '一覧だけ表示',
            'completed' => false,
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
            'importance' => 'medium',
            'category' => 'task',
            'reminders' => [],
            'notified_at' => [],
        ]);

        $response = $this->actingAs($user)->get('/todos?display=list');
        $response->assertOk();
        $response->assertSee('todo-table', false);
        $response->assertDontSee('todos-calendar-panel', false);
        $response->assertSee('一覧だけ表示');
    }

    public function test_calendar_page_includes_todo_items_js_and_modal_before_script(): void
    {
        $user = $this->makeUser();
        $todo = Todo::create([
            'user_id' => $user->id,
            'title' => 'モーダル確認',
            'completed' => false,
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
            'importance' => 'medium',
            'category' => 'task',
            'reminders' => [],
            'notified_at' => [],
            'google_meet_link' => 'https://meet.google.com/abc-defg-hij',
        ]);

        $html = $this->actingAs($user)->get('/todos')->assertOk()->getContent();
        $modalPos = strpos($html, 'id="todo-modal"');
        $scriptPos = strpos($html, 'const TODO_ITEMS');
        $this->assertNotFalse($modalPos);
        $this->assertNotFalse($scriptPos);
        $this->assertLessThan($scriptPos, $modalPos, 'モーダルがスクリプトより前にあること');
        $this->assertStringContainsString('data-todo-id="'.$todo->id.'"', $html);
        $this->assertStringContainsString('meet.google.com', $html);
        $this->assertStringContainsString('openTodoModal', $html);
        $this->assertStringContainsString('event-meet-badge', $html);
    }

    public function test_todos_calendar_supports_week_day_year_views(): void
    {
        $user = $this->makeUser();
        Todo::create([
            'user_id' => $user->id,
            'title' => '週ビュー確認',
            'completed' => false,
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
            'importance' => 'medium',
            'category' => 'task',
            'reminders' => [],
            'notified_at' => [],
        ]);

        $week = $this->actingAs($user)->get('/todos?view=week&date='.now()->format('Y-m-d'));
        $week->assertOk();
        $week->assertSee('calendar-week-view', false);
        $week->assertSee('calendar-toolbar-inline', false);
        $week->assertSee('週ビュー確認');

        $day = $this->actingAs($user)->get('/todos?view=day&date='.now()->format('Y-m-d'));
        $day->assertOk();
        $day->assertSee('calendar-day-view', false);

        $year = $this->actingAs($user)->get('/todos?view=year&year='.now()->format('Y'));
        $year->assertOk();
        $year->assertSee('calendar-year-view', false);
    }

    public function test_list_edit_shows_meet_link(): void
    {
        $user = $this->makeUser();
        $todo = Todo::create([
            'user_id' => $user->id,
            'title' => 'Meet付き',
            'completed' => false,
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
            'importance' => 'medium',
            'category' => 'task',
            'reminders' => [],
            'notified_at' => [],
            'google_meet_link' => 'https://meet.google.com/xyz-uvwx-yz0',
        ]);

        $response = $this->actingAs($user)->get('/todos?display=list&edit='.$todo->id);
        $response->assertOk();
        $response->assertSee('https://meet.google.com/xyz-uvwx-yz0');
        $response->assertSee('Google Meet');
    }

    public function test_store_ignores_notifications_without_start_time(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->post('/todos', [
            'titles' => '時間なし通知',
            'startDate' => now()->format('Y-m-d'),
            'dateMode' => 'single',
            'importance' => 'medium',
            'category' => 'task',
            'reminders' => ['5min'],
            'notifyVia' => 'line',
            'returnTo' => '/todos',
        ])->assertRedirect();

        $todo = Todo::query()->first();
        $this->assertNotNull($todo);
        $this->assertSame([], $todo->reminders ?? []);
        $this->assertNull($todo->notify_via);
    }

    public function test_store_keeps_notifications_with_start_time(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->post('/todos', [
            'titles' => '時間あり通知',
            'startDate' => now()->format('Y-m-d'),
            'dateMode' => 'single',
            'startTime' => '10:00',
            'endTime' => '11:00',
            'importance' => 'medium',
            'category' => 'task',
            'reminders' => ['5min'],
            'notifyVia' => 'line',
            'returnTo' => '/todos',
        ])->assertRedirect();

        $todo = Todo::query()->first();
        $this->assertNotNull($todo);
        $this->assertSame(['5min'], $todo->reminders ?? []);
        $this->assertSame('line', $todo->notify_via);
    }

    public function test_store_and_update_persist_optional_memo(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->post('/todos', [
            'title' => '買い物',
            'memo' => "牛乳\n卵",
            'startDate' => now()->format('Y-m-d'),
            'dateMode' => 'single',
            'importance' => 'medium',
            'category' => 'task',
            'returnTo' => '/todos',
        ])->assertRedirect();

        $todo = Todo::query()->first();
        $this->assertNotNull($todo);
        $this->assertSame('買い物', $todo->title);
        $this->assertSame("牛乳\n卵", $todo->memo);

        $this->actingAs($user)->post('/todos/'.$todo->id.'/update', [
            'title' => '買い物',
            'memo' => '',
            'startDate' => now()->format('Y-m-d'),
            'dateMode' => 'single',
            'importance' => 'medium',
            'category' => 'task',
            'returnTo' => '/todos',
        ])->assertRedirect();

        $todo->refresh();
        $this->assertNull($todo->memo);
    }

    public function test_todos_modal_uses_single_line_title_and_optional_memo(): void
    {
        $user = $this->makeUser();
        $html = $this->actingAs($user)->get('/todos')->assertOk()->getContent();

        $this->assertStringContainsString('id="modal-title"', $html);
        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringContainsString('タイトル（1行1件）', $html);
        $this->assertStringContainsString('data-todo-memo', $html);
        $this->assertStringContainsString('todo-enable-memo', $html);
        $this->assertDoesNotMatchRegularExpression('/id="modal-title"[^>]*><\/textarea>/', $html);
    }

    public function test_update_clears_notifications_when_time_removed(): void
    {
        $user = $this->makeUser();
        $todo = Todo::create([
            'user_id' => $user->id,
            'title' => '通知あり',
            'completed' => false,
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'importance' => 'medium',
            'category' => 'task',
            'reminders' => ['5min'],
            'notify_via' => 'line',
            'notified_at' => [],
        ]);

        $this->actingAs($user)->post('/todos/'.$todo->id.'/update', [
            'title' => '通知あり',
            'startDate' => now()->format('Y-m-d'),
            'dateMode' => 'single',
            'startTime' => '',
            'endTime' => '',
            'importance' => 'medium',
            'category' => 'task',
            'reminders' => ['5min'],
            'notifyVia' => 'line',
            'returnTo' => '/todos',
        ])->assertRedirect();

        $todo->refresh();
        $this->assertSame([], $todo->reminders ?? []);
        $this->assertNull($todo->notify_via);
    }

    public function test_work_mode_shows_calendar_select_toggle_and_modal_without_banner(): void
    {
        $user = $this->makeUser();
        $user->app_context = 'work';
        $user->save();

        $response = $this->actingAs($user)
            ->withSession(['app_context' => 'work'])
            ->get('/todos');

        $response->assertOk();
        $response->assertDontSee('仕事モード: プライベートの ToDo は表示されません。');
        $response->assertDontSee('選択中の Google カレンダー予定も表示します。');
        $response->assertSee('data-open-todo-gcal-modal', false);
        $response->assertSee('id="todo-gcal-modal"', false);
        $response->assertSee('id="google-calendar"', false);
    }

    public function test_personal_mode_hides_calendar_select_toggle(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->get('/todos');
        $response->assertOk();
        $response->assertDontSee('id="todo-gcal-open"', false);
        $response->assertDontSee('id="todo-gcal-modal"', false);
    }
}
