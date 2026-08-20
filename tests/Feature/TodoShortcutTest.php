<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\TodoShortcutCategory;
use App\Models\User;
use App\Services\TodoShortcutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TodoShortcutTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'email' => 'shortcut@example.com',
            'display_name' => 'Shortcut',
            'password' => Hash::make('password123'),
            'role' => UserRole::Standard,
        ]);
    }

    public function test_settings_page_and_category_title_crud(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->get('/todos?display=settings')
            ->assertOk()
            ->assertSee('クイック入力設定')
            ->assertSee('設定', false);

        $this->actingAs($user)->post('/todos/shortcuts/categories', [
            'name' => '移動',
            'icon' => '🚗',
            'returnTo' => '/todos?display=settings',
        ])->assertRedirect('/todos?display=settings');

        $cat = TodoShortcutCategory::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($cat);
        $this->assertSame('移動', $cat->name);
        $this->assertSame('🚗', $cat->icon);

        $this->actingAs($user)->post('/todos/shortcuts/titles', [
            'category_id' => $cat->id,
            'title' => '松本整形外科に行く',
            'time_mode' => 'range',
            'start_time' => '10:00',
            'end_time' => '11:00',
            'notifyVia' => 'push',
            'reminders' => ['30min', 'at_time'],
            'reminderTime' => '09:30',
            'returnTo' => '/todos?display=settings',
        ])->assertRedirect('/todos?display=settings');

        $list = app(TodoShortcutService::class)->listForUser((int) $user->id);
        $this->assertCount(1, $list);
        $this->assertSame('松本整形外科に行く', $list[0]['titles'][0]['title']);
        $this->assertSame('10:00', $list[0]['titles'][0]['startTime']);
        $this->assertSame('11:00', $list[0]['titles'][0]['endTime']);
        $this->assertSame('push', $list[0]['titles'][0]['notifyVia']);
        $this->assertContains('30min', $list[0]['titles'][0]['reminders']);

        $this->actingAs($user)->post('/todos/shortcuts/titles', [
            'category_id' => $cat->id,
            'title' => '薬局に寄る',
            'time_mode' => 'point',
            'point_time' => '15:30',
            'returnTo' => '/todos?display=settings',
        ])->assertRedirect('/todos?display=settings');

        $list = app(TodoShortcutService::class)->listForUser((int) $user->id);
        $this->assertCount(2, $list[0]['titles']);
        $this->assertSame('15:30', $list[0]['titles'][1]['startTime']);
        $this->assertNull($list[0]['titles'][1]['endTime']);

        $this->actingAs($user)->post('/todos/shortcuts/titles', [
            'category_id' => $cat->id,
            'title' => '時間なしの用事',
            'time_mode' => 'none',
            'returnTo' => '/todos?display=settings',
        ])->assertRedirect('/todos?display=settings');

        $list = app(TodoShortcutService::class)->listForUser((int) $user->id);
        $this->assertNull($list[0]['titles'][2]['startTime']);
        $this->assertNull($list[0]['titles'][2]['endTime']);

        $this->actingAs($user)->get('/todos')
            ->assertOk()
            ->assertSee('todo-shortcut-icons', false)
            ->assertSee('data-shortcut-cat-id', false)
            ->assertSee('todo-shortcut-picker', false);
    }
}
