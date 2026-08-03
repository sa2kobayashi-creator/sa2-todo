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
}
