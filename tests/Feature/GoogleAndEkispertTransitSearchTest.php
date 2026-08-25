<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\EkispertConfigService;
use App\Services\GoogleRoutesConfigService;
use App\Services\Transit\RouteSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAndEkispertTransitSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'transit.provider' => 'auto',
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
            'email' => 'route-new-apis@example.com',
            'display_name' => 'Route User',
            'password' => Hash::make('password'),
            'role' => UserRole::SuperAdmin,
        ]);
    }

    public function test_search_uses_google_routes_when_configured(): void
    {
        app(GoogleRoutesConfigService::class)->save(true, ['api_key' => 'AIzaSyRoutesSearchKey0000000000']);
        Http::fake([
            'routes.googleapis.com/*' => Http::response([
                'routes' => [[
                    'duration' => '1800s',
                    'legs' => [[
                        'steps' => [
                            ['travelMode' => 'WALK', 'staticDuration' => '300s'],
                            [
                                'travelMode' => 'TRANSIT',
                                'staticDuration' => '1500s',
                                'transitDetails' => [
                                    'stopDetails' => [
                                        'departureStop' => ['name' => '天神'],
                                        'arrivalStop' => ['name' => '博多'],
                                        'departureTime' => '2026-08-26T08:10:00+09:00',
                                        'arrivalTime' => '2026-08-26T08:30:00+09:00',
                                    ],
                                    'transitLine' => [
                                        'name' => '地下鉄空港線',
                                        'nameShort' => '空港線',
                                        'vehicle' => ['type' => 'SUBWAY'],
                                    ],
                                ],
                            ],
                        ],
                    ]],
                    'travelAdvisory' => ['transitFare' => ['currencyCode' => 'JPY', 'units' => '260']],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->user())->postJson('/transit/search', [
            'from' => '天神',
            'to' => '博多',
            'departureAt' => '2026-08-26T08:00',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('engine', 'Google Maps Routes')
            ->assertJsonPath('itineraries.0.departureTime', '08:10')
            ->assertJsonPath('itineraries.0.arrivalTime', '08:30')
            ->assertJsonPath('itineraries.0.fareLabel', '¥260')
            ->assertJsonPath('itineraries.0.legs.1.routeName', '空港線');
    }

    public function test_search_uses_ekispert_when_configured(): void
    {
        app(EkispertConfigService::class)->save(true, [], ['api_key' => 'ekispert-search-key']);
        app(RouteSearchService::class)->saveSelectedKey('ekispert');
        Http::fake([
            'api.ekispert.jp/*' => Http::response([
                'ResultSet' => [
                    'Course' => [
                        'Price' => [['kind' => 'FareSummary', 'Oneway' => '260']],
                        'Route' => [
                            'transferCount' => '0',
                            'Point' => [
                                ['Station' => ['Name' => '天神']],
                                ['Station' => ['Name' => '博多']],
                            ],
                            'Line' => [[
                                'Name' => '地下鉄空港線',
                                'Type' => 'train',
                                'DepartureState' => ['Datetime' => ['text' => '2026-08-26T08:10:00+09:00']],
                                'ArrivalState' => ['Datetime' => ['text' => '2026-08-26T08:30:00+09:00']],
                            ]],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->actingAs($this->user())->postJson('/transit/search', [
            'from' => '天神',
            'to' => '博多',
            'departureAt' => '2026-08-26T08:00',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('engine', '駅すぱあと')
            ->assertJsonPath('itineraries.0.departureTime', '08:10')
            ->assertJsonPath('itineraries.0.transfers', 0)
            ->assertJsonPath('itineraries.0.fareLabel', '¥260')
            ->assertJsonPath('itineraries.0.legs.0.routeName', '地下鉄空港線');
    }

    public function test_google_failure_falls_back_to_the_builtin_engine(): void
    {
        app(GoogleRoutesConfigService::class)->save(true, ['api_key' => 'AIzaSyRoutesFailKey000000000000']);
        Http::fake([
            'routes.googleapis.com/*' => Http::response(['error' => ['message' => 'quota']], 429),
        ]);

        $response = $this->actingAs($this->user())->postJson('/transit/search', [
            'from' => '天神',
            'to' => '博多',
            'departureAt' => '2026-08-26T08:00',
        ]);

        $response->assertOk()->assertJsonPath('engine', 'RAPTOR');
        $this->assertStringContainsString('Google Maps Routes', (string) $response->json('engineNote'));
    }
}
