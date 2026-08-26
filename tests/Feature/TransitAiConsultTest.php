<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\TransitFavorite;
use App\Models\User;
use App\Services\TransitAiConsultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TransitAiConsultTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'email' => 'transit-ai@example.com',
            'display_name' => 'Transit AI',
            'password' => Hash::make('password'),
            'role' => UserRole::SuperAdmin,
        ]);
    }

    private function enableWorkersAi(): void
    {
        $this->actingAs($this->user())->post('/settings/ai/workers-ai', [
            'enabled' => '1',
            'account_id' => '7d9d8a36d8aacca7fc28fea91d0945c9',
            'api_token' => 'cf-token-test',
        ]);
    }

    public function test_consult_searches_the_route_api_then_answers_from_those_facts(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['response' => '最短は地下鉄空港線です。運賃は結果のとおりです。'],
            ], 200),
        ]);
        $this->enableWorkersAi();
        $actor = User::query()->where('email', 'transit-ai@example.com')->firstOrFail();

        $response = $this->actingAs($actor)->postJson('/transit/ai-ask', [
            'prompt' => '天神から博多駅までの行き方を教えて',
            'departureAt' => '2026-08-26T08:00',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('searched', true)
            ->assertJsonPath('search.from', '天神')
            ->assertJsonPath('search.to', '博多駅');
        $this->assertNotEmpty($response->json('itineraries'));

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/ai/run/')) {
                return false;
            }
            $system = (string) ($request['messages'][0]['content'] ?? '');

            return str_contains($system, 'API itineraries')
                && str_contains($system, '天神')
                && (str_contains($system, '¥') || str_contains($system, '運賃'));
        });
    }

    public function test_follow_up_reuses_the_last_search_and_changes_preference(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['response' => '最安の候補です。'],
            ], 200),
        ]);
        $this->enableWorkersAi();
        $actor = User::query()->where('email', 'transit-ai@example.com')->firstOrFail();

        $response = $this->actingAs($actor)->postJson('/transit/ai-ask', [
            'prompt' => 'もっと安いルートは？',
            'lastSearch' => [
                'from' => '天神',
                'to' => '博多',
                'departureAt' => '2026-08-26T08:00',
                'timeType' => 'departure',
                'preference' => 'fastest',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('searched', true)
            ->assertJsonPath('search.from', '天神')
            ->assertJsonPath('search.to', '博多')
            ->assertJsonPath('search.preference', 'cheapest');
    }

    public function test_without_stations_it_does_not_invent_a_search(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['response' => '出発地と到着地を教えてください。'],
            ], 200),
        ]);
        $this->enableWorkersAi();
        $actor = User::query()->where('email', 'transit-ai@example.com')->firstOrFail();

        $this->actingAs($actor)->postJson('/transit/ai-ask', [
            'prompt' => '今日はどの電車が空いていますか',
        ])
            ->assertOk()
            ->assertJsonPath('searched', false)
            ->assertJsonPath('search', null);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/ai/run/')) {
                return false;
            }

            return str_contains((string) ($request['messages'][0]['content'] ?? ''), 'No route search yet');
        });
    }

    public function test_transit_page_busts_transit_js_cache_and_can_render_ai_results_without_it(): void
    {
        $user = $this->user();

        $html = $this->actingAs($user)->get('/transit')->assertOk()->getContent();

        $this->assertStringNotContainsString('transit.js?v=10', $html);
        $this->assertMatchesRegularExpression('/transit\.js\?v=\d{5,}/', $html);
        $this->assertStringContainsString('transitAiResult', $html);
        $this->assertStringContainsString('showTransitResultsFallback', $html);
        $this->assertStringContainsString('transit-search-results', $html);
        $this->assertStringContainsString('speech-dictation.js', $html);
        $this->assertStringContainsString('transit-from-mic', $html);
        $this->assertStringContainsString('transit-ai-mic', $html);
    }

    public function test_a_saved_route_name_fills_in_the_stations(): void
    {
        $user = $this->user();
        TransitFavorite::query()->create([
            'user_id' => $user->id,
            'category' => 'nishitetsu_bus',
            'name' => '通勤',
            'from_place' => '姪浜',
            'to_place' => '天神',
            'line_name' => '',
            'sort_order' => 1,
        ]);

        $query = app(TransitAiConsultService::class)->resolveQuery($user, '通勤の雨の日の乗り換え', [], []);

        $this->assertSame('姪浜', $query['from']);
        $this->assertSame('天神', $query['to']);
        $this->assertSame('fewest_transfers', $query['preference']);
    }
}
