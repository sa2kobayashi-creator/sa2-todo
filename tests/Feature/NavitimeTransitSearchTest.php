<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\GoogleMapsConfigService;
use App\Services\NavitimeConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NavitimeTransitSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'services.navitime.api_key' => '',
            'services.navitime.base_url' => '',
            'services.navitime.node_host' => '',
            'services.google_maps.api_key' => '',
            'services.google_routes.api_key' => '',
        ]);
    }

    private function user(): User
    {
        return User::create([
            'email' => 'navitime-search@example.com',
            'display_name' => 'Transit User',
            'password' => Hash::make('password'),
            'role' => UserRole::SuperAdmin,
        ]);
    }

    private function enableNavitime(): void
    {
        app(NavitimeConfigService::class)->save(true, ['mode' => 'rapidapi'], ['api_key' => 'rapid-key']);
        app(GoogleMapsConfigService::class)->saveConfig(true, ['api_key' => 'AIzaSyForGeocodingInTests00000000']);
    }

    /** @return array<string, mixed> */
    private function routePayload(): array
    {
        return [
            'items' => [
                [
                    'summary' => [
                        'move' => [
                            'transit_count' => 1,
                            'fare' => ['unit_0' => 260, 'unit_48' => 255],
                            'type' => 'move',
                            'from_time' => '2026-08-25T08:00:00+09:00',
                            'to_time' => '2026-08-25T08:30:00+09:00',
                            'time' => 30,
                            'distance' => 8000,
                        ],
                    ],
                    'sections' => [
                        ['type' => 'point', 'name' => '天神'],
                        [
                            'type' => 'move',
                            'move' => 'walk',
                            'from_time' => '2026-08-25T08:00:00+09:00',
                            'to_time' => '2026-08-25T08:05:00+09:00',
                            'time' => 5,
                            'line_name' => '徒歩',
                        ],
                        ['type' => 'point', 'name' => '西鉄福岡（天神）'],
                        [
                            'type' => 'move',
                            'move' => 'local_train',
                            'transport' => [
                                'name' => '西鉄天神大牟田線',
                                'company' => ['name' => '西日本鉄道'],
                            ],
                            'from_time' => '2026-08-25T08:10:00+09:00',
                            'to_time' => '2026-08-25T08:30:00+09:00',
                            'time' => 20,
                            'line_name' => '西鉄天神大牟田線',
                        ],
                        ['type' => 'point', 'name' => '薬院'],
                    ],
                ],
            ],
        ];
    }

    private function fakeGeocodeAnd(mixed $routeResponse): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'results' => [['geometry' => ['location' => ['lat' => 33.5904, 'lng' => 130.4017]]]],
            ], 200),
            'navitime-route-totalnavi.p.rapidapi.com/*' => $routeResponse,
        ]);
    }

    public function test_search_uses_navitime_when_configured(): void
    {
        $this->enableNavitime();
        $this->fakeGeocodeAnd(Http::response($this->routePayload(), 200));

        $response = $this->actingAs($this->user())->postJson('/transit/search', [
            'from' => '天神',
            'to' => '薬院',
            'departureAt' => '2026-08-25T08:00',
            'preference' => 'fastest',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('engine', 'NAVITIME')
            ->assertJsonPath('itineraries.0.departureTime', '08:00')
            ->assertJsonPath('itineraries.0.arrivalTime', '08:30')
            ->assertJsonPath('itineraries.0.transfers', 1)
            ->assertJsonPath('itineraries.0.fareLabel', '¥260')
            ->assertJsonPath('itineraries.0.usesNishitetsuBus', true)
            ->assertJsonPath('itineraries.0.legs.0.type', 'walk')
            ->assertJsonPath('itineraries.0.legs.1.type', 'ride')
            ->assertJsonPath('itineraries.0.legs.1.routeName', '西鉄天神大牟田線')
            ->assertJsonPath('itineraries.0.legs.1.boardTime', '08:10')
            ->assertJsonPath('itineraries.0.legs.1.waitSec', 300);
    }

    public function test_search_sends_the_requested_datetime_and_order(): void
    {
        $this->enableNavitime();
        $this->fakeGeocodeAnd(Http::response($this->routePayload(), 200));

        $this->actingAs($this->user())->postJson('/transit/search', [
            'from' => '天神',
            'to' => '薬院',
            'departureAt' => '2026-08-25T09:30',
            'timeType' => 'arrival',
            'preference' => 'cheapest',
        ])->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'route_transit')) {
                return false;
            }

            return $request['goal_time'] === '2026-08-25T09:30:00'
                && $request['order'] === 'fare'
                && $request->hasHeader('X-RapidAPI-Key', 'rapid-key');
        });
    }

    public function test_search_falls_back_to_the_builtin_engine_when_navitime_fails(): void
    {
        $this->enableNavitime();
        $this->fakeGeocodeAnd(Http::response(['message' => 'quota'], 429));

        $response = $this->actingAs($this->user())->postJson('/transit/search', [
            'from' => '天神',
            'to' => '博多',
            'departureAt' => '2026-08-25T08:00',
        ]);

        $response->assertOk()->assertJsonPath('engine', 'RAPTOR');
        $this->assertStringContainsString('NAVITIME', (string) $response->json('engineNote'));
    }

    public function test_search_uses_the_builtin_engine_when_navitime_is_not_configured(): void
    {
        Http::fake();

        $response = $this->actingAs($this->user())->postJson('/transit/search', [
            'from' => '天神',
            'to' => '博多',
            'departureAt' => '2026-08-25T08:00',
        ]);

        $response->assertOk()->assertJsonPath('engine', 'RAPTOR');
        $this->assertNull($response->json('engineNote'));
        Http::assertNothingSent();
    }

    public function test_coordinates_are_accepted_without_geocoding(): void
    {
        $this->enableNavitime();
        Http::fake([
            'navitime-route-totalnavi.p.rapidapi.com/*' => Http::response($this->routePayload(), 200),
        ]);

        $this->actingAs($this->user())->postJson('/transit/search', [
            'from' => '33.5904,130.4017',
            'to' => '33.5897,130.4017',
            'departureAt' => '2026-08-25T08:00',
        ])->assertOk()->assertJsonPath('engine', 'NAVITIME');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'start=33.5904%2C130.4017'));
    }
}
