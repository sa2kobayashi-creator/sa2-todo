<?php

namespace Tests\Feature;

use App\Enums\AppContext;
use App\Enums\UserRole;
use App\Models\Todo;
use App\Models\User;
use App\Services\AppContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AppContextAndGoogleSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'email' => 'ctx@example.com',
            'display_name' => 'Ctx',
            'password' => Hash::make('password'),
            'role' => UserRole::Admin,
        ]);
    }

    public function test_context_switch_persists(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)
            ->post('/app-context', ['context' => 'work', 'redirect' => '/dashboard'])
            ->assertRedirect('/dashboard');

        $this->assertSame('work', $user->fresh()->app_context);
        $this->assertSame('work', session(AppContextService::SESSION_KEY));
    }

    public function test_todos_are_separated_by_context(): void
    {
        $user = $this->makeUser();
        Todo::create([
            'user_id' => $user->id,
            'context' => 'personal',
            'title' => 'private task',
            'completed' => false,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-01',
            'importance' => 'medium',
            'category' => 'task',
            'reminders' => [],
            'notified_at' => [],
        ]);
        Todo::create([
            'user_id' => $user->id,
            'context' => 'work',
            'title' => 'work task',
            'completed' => false,
            'start_date' => '2026-08-02',
            'end_date' => '2026-08-02',
            'importance' => 'medium',
            'category' => 'task',
            'reminders' => [],
            'notified_at' => [],
        ]);

        app(AppContextService::class)->set($user, AppContext::Personal);
        $personal = app(\App\Services\TodoService::class)->listTodos($user->id, AppContext::Personal);
        $this->assertCount(1, $personal);
        $this->assertSame('private task', $personal->first()['title']);

        $work = app(\App\Services\TodoService::class)->listTodos($user->id, AppContext::Work);
        $this->assertCount(1, $work);
        $this->assertSame('work task', $work->first()['title']);
    }

    public function test_work_todo_requires_date(): void
    {
        $user = $this->makeUser();
        app(AppContextService::class)->set($user, AppContext::Work);

        $this->expectException(\InvalidArgumentException::class);
        app(\App\Services\TodoService::class)->addTodos(['no date'], [
            'userId' => $user->id,
            'context' => 'work',
        ]);
    }
}
