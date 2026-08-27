<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsagePageTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(UserRole $role, string $email): User
    {
        return User::create([
            'email' => $email,
            'display_name' => $role->label(),
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    public function test_usage_page_shows_free_tier_values_for_integrations_and_storage(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'usage-free-tier@example.com');

        $this->actingAs($admin)
            ->get('/settings?section=usage')
            ->assertOk()
            ->assertSee('無料枠', false)
            ->assertSee('ユーザーあたり1日 30 回', false)
            ->assertSee('1日 50,000 文字', false)
            ->assertSee('月 500,000 文字', false)
            ->assertSee('10,000 単位/日', false)
            ->assertSee('月 200 通', false)
            ->assertSee('月 1,000 参加者分', false)
            ->assertSee('Light', false)
            ->assertSee('Standard', false)
            ->assertSee('公式APIの使用量', false)
            ->assertSee('Gemini の API キーでは使用量を取得できません', false)
            ->assertDontSee('アプリ上限 ユーザーあたり1日 10 回。Stability', false);
    }
}
