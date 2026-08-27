<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TransitPreferredOperatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'transit.provider' => 'raptor',
            'services.navitime.api_key' => '',
            'services.navitime.base_url' => '',
            'services.google_maps.api_key' => '',
            'services.google_routes.api_key' => '',
            'services.ekispert.api_key' => '',
        ]);
    }

    private function user(): User
    {
        return User::create([
            'email' => 'transit-operator@example.com',
            'display_name' => 'Transit Operator User',
            'password' => Hash::make('password'),
            'role' => UserRole::SuperAdmin,
        ]);
    }

    public function test_transit_page_offers_a_searchable_national_operator_picker(): void
    {
        $this->actingAs($this->user())->get('/transit')
            ->assertOk()
            ->assertSee(__('地域の優先'), false)
            ->assertSee(__('優先する交通機関'), false)
            ->assertSee(__('指定なし'), false)
            ->assertSee('西鉄バス', false)
            ->assertSee('東京メトロ', false)
            ->assertSee('transit-operator-filter', false)
            ->assertDontSee(__('福岡は西鉄バスを優先'), false)
            ->assertDontSee('transit-prefer-nishitetsu', false)
            ->assertSee('transit-places-swap', false)
            ->assertSee(__('出発と到着を入れ替え'), false)
            ->assertSee('transit-from-mic', false)
            ->assertSee('transit-to-mic', false)
            ->assertSee('transit-ai-mic', false)
            ->assertSee('speech-dictation.js', false);

        $css = file_get_contents(public_path('app.css'));
        $this->assertNotFalse($css);
        $this->assertStringContainsString('gmp-place-autocomplete::part(input)', $css);
        $this->assertStringContainsString('max-width: 100%', $css);
    }

    public function test_search_marks_itineraries_for_the_chosen_operator(): void
    {
        $user = $this->user();

        $nishitetsu = $this->actingAs($user)->postJson('/transit/search', [
            'from' => '天神',
            'to' => '博多',
            'departureAt' => '2026-08-25T08:00',
            'engine' => 'raptor',
            'preferredOperator' => 'nishitetsu_bus',
        ]);

        $nishitetsu->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('preferredOperator', 'nishitetsu_bus')
            ->assertJsonPath('preferredOperatorName', '西鉄バス');

        $matched = collect($nishitetsu->json('itineraries'))->contains(
            fn (array $itinerary) => ! empty($itinerary['usesPreferredOperator'])
        );
        $this->assertTrue($matched, '西鉄バスを含む経路に優先フラグが付くこと');

        $metro = $this->actingAs($user)->postJson('/transit/search', [
            'from' => '天神',
            'to' => '博多',
            'departureAt' => '2026-08-25T08:00',
            'engine' => 'raptor',
            'preferredOperator' => 'tokyo_metro',
        ]);

        $metro->assertOk()->assertJsonPath('preferredOperator', 'tokyo_metro');
        foreach ($metro->json('itineraries') as $itinerary) {
            $this->assertFalse((bool) ($itinerary['usesPreferredOperator'] ?? false));
        }
    }

    public function test_hakata_to_meinohama_search_returns_routes_with_nishitetsu_preference(): void
    {
        $response = $this->actingAs($this->user())->postJson('/transit/search', [
            'from' => '博多駅',
            'to' => '姪浜',
            'departureAt' => '2026-08-26T10:30',
            'engine' => 'raptor',
            'preferredOperator' => 'nishitetsu_bus',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('itineraries.0.usesPreferredOperator', true);
        $this->assertNotEmpty($response->json('itineraries'));
        $this->assertTrue(
            collect($response->json('itineraries'))->contains(
                fn (array $itinerary) => str_contains((string) ($itinerary['summary'] ?? ''), '西鉄バス')
            )
        );
    }

    public function test_hakata_to_meinohama_without_preference_includes_subway_and_bus(): void
    {
        $response = $this->actingAs($this->user())->postJson('/transit/search', [
            'from' => '博多駅',
            'to' => '姪浜',
            'departureAt' => '2026-08-26T10:30',
            'engine' => 'raptor',
            'preferredOperator' => '',
        ]);

        $response->assertOk()->assertJsonPath('ok', true);
        $summaries = collect($response->json('itineraries'))->pluck('summary')->implode(' ');
        $this->assertStringContainsString('地下鉄', $summaries);
        $this->assertStringContainsString('西鉄バス', $summaries);
        $this->assertGreaterThanOrEqual(2, count($response->json('itineraries')));
    }

    public function test_hakata_to_meinohama_subway_preference_puts_subway_first(): void
    {
        $response = $this->actingAs($this->user())->postJson('/transit/search', [
            'from' => '博多駅',
            'to' => '姪浜',
            'departureAt' => '2026-08-26T10:30',
            'engine' => 'raptor',
            'preferredOperator' => 'fukuoka_subway',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('itineraries.0.usesPreferredOperator', true);
        $this->assertStringContainsString('地下鉄', (string) $response->json('itineraries.0.summary'));
    }
}
