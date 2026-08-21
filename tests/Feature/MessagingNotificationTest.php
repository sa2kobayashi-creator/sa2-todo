<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MessagingConnection;
use App\Models\Todo;
use App\Models\User;
use App\Services\FacebookMessagingConfigService;
use App\Services\LineConfigService;
use App\Services\MessagingLinkService;
use App\Services\ReminderNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MessagingNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::create([
            'email' => 'admin-msg@example.com',
            'display_name' => 'Admin',
            'password' => Hash::make('password'),
            // LINE などのチャネル秘密鍵を保存できるのはスーパー管理者だけ
            'role' => UserRole::SuperAdmin,
        ]);
    }

    private function configureLine(): void
    {
        app(LineConfigService::class)->saveConfig(true, [
            'channel_access_token' => 'line-token',
            'channel_secret' => 'line-secret',
            'bot_basic_id' => '@sa2bot',
        ]);
    }

    private function configureMessenger(): void
    {
        app(FacebookMessagingConfigService::class)->saveConfig(true, [
            'page_access_token' => 'page-token',
            'app_secret' => 'app-secret',
            'verify_token' => 'verify-me',
            'page_name' => 'Sa2 Page',
        ]);
    }

    public function test_line_webhook_links_with_code(): void
    {
        $this->configureLine();
        $user = $this->makeAdmin();
        $code = app(MessagingLinkService::class)->issueCode($user, 'line');

        $body = json_encode([
            'events' => [[
                'type' => 'message',
                'replyToken' => 'reply-1',
                'source' => ['userId' => 'Uline123'],
                'message' => ['type' => 'text', 'text' => $code],
            ]],
        ], JSON_THROW_ON_ERROR);

        $signature = base64_encode(hash_hmac('sha256', $body, 'line-secret', true));

        Http::fake([
            'api.line.me/*' => Http::response([], 200),
        ]);

        $this->call(
            'POST',
            '/webhooks/line',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_LINE_SIGNATURE' => $signature,
            ],
            $body
        )->assertOk();

        $this->assertDatabaseHas('messaging_connections', [
            'user_id' => $user->id,
            'provider' => 'line',
            'external_user_id' => 'Uline123',
        ]);
    }

    public function test_messenger_webhook_verification(): void
    {
        $this->configureMessenger();

        $this->get('/webhooks/messenger?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=abc123')
            ->assertOk()
            ->assertSee('abc123');
    }

    public function test_messenger_webhook_links_with_code(): void
    {
        $this->configureMessenger();
        $user = $this->makeAdmin();
        $code = app(MessagingLinkService::class)->issueCode($user, 'messenger');

        $body = json_encode([
            'entry' => [[
                'messaging' => [[
                    'sender' => ['id' => 'psid-9'],
                    'message' => ['text' => "code {$code}"],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $signature = 'sha256='.hash_hmac('sha256', $body, 'app-secret');

        Http::fake([
            'graph.facebook.com/*' => Http::response(['message_id' => 'm1'], 200),
        ]);

        $this->call(
            'POST',
            '/webhooks/messenger',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $body
        )->assertOk();

        $this->assertDatabaseHas('messaging_connections', [
            'user_id' => $user->id,
            'provider' => 'messenger',
            'external_user_id' => 'psid-9',
        ]);
    }

    public function test_send_reminders_dispatches_line(): void
    {
        $this->configureLine();
        $user = $this->makeAdmin();

        MessagingConnection::create([
            'user_id' => $user->id,
            'provider' => 'line',
            'external_user_id' => 'Uline999',
            'linked_at' => now(),
        ]);

        Todo::create([
            'user_id' => $user->id,
            'title' => '牛乳を買う',
            'completed' => false,
            'start_date' => now()->toDateString(),
            'start_time' => '10:00',
            'importance' => 'medium',
            'category' => 'task',
            'reminders' => ['at9am'],
            'notify_via' => 'line',
            'notified_at' => [],
        ]);

        Http::fake([
            'api.line.me/*' => Http::response([], 200),
        ]);

        $stats = app(ReminderNotificationService::class)->dispatchDueReminders(now()->setTime(9, 5));

        $this->assertSame(1, $stats['sent']);
        $this->assertNotEmpty(Todo::first()->notified_at['at9am'] ?? null);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.line.me'));
    }

    public function test_send_reminders_supports_same_day_clock_time(): void
    {
        $this->configureLine();
        $user = $this->makeAdmin();

        MessagingConnection::create([
            'user_id' => $user->id,
            'provider' => 'line',
            'external_user_id' => 'Uline888',
            'linked_at' => now(),
        ]);

        Todo::create([
            'user_id' => $user->id,
            'title' => '資料を送る',
            'completed' => false,
            'start_date' => now()->toDateString(),
            'start_time' => '18:00',
            'importance' => 'medium',
            'category' => 'task',
            'reminders' => ['at:08:30'],
            'notify_via' => 'line',
            'notified_at' => [],
        ]);

        Http::fake([
            'api.line.me/*' => Http::response([], 200),
        ]);

        $stats = app(ReminderNotificationService::class)->dispatchDueReminders(now()->setTime(8, 35));

        $this->assertSame(1, $stats['sent']);
        $this->assertNotEmpty(Todo::first()->notified_at['at:08:30'] ?? null);
    }

    public function test_issue_code_requires_auth(): void
    {
        $this->configureLine();
        $this->post('/settings/messaging/line/code')->assertRedirect('/login');
    }

    public function test_save_line_channel_from_settings_ui(): void
    {
        $user = $this->makeAdmin();
        Http::fake([
            'api.line.me/v2/bot/info' => Http::response(['displayName' => 'Sa2 Bot'], 200),
        ]);

        $this->actingAs($user)->post('/settings/messaging/line/channel', [
            'enabled' => '1',
            'channel_access_token' => 'ui-token',
            'channel_secret' => 'ui-secret',
            'bot_basic_id' => '@ui',
        ])->assertRedirect();

        $this->assertTrue(app(LineConfigService::class)->isReady());
        $this->assertSame('ui-token', app(LineConfigService::class)->channelAccessToken());

        $this->actingAs($user)
            ->postJson('/settings/messaging/line/channel/test')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_integration_settings_shows_panels(): void
    {
        $user = $this->makeAdmin();
        $this->actingAs($user)
            ->get('/settings?section=integration')
            ->assertOk()
            ->assertSee('LINE連携設定', false)
            ->assertSee('Facebook Messenger 通知連携', false)
            ->assertSee('Channel Access Token', false)
            ->assertSee('設定手順', false);
    }
}
