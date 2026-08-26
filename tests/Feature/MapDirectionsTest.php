<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\GoogleRoutesConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MapDirectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google_routes.api_key' => '',
            'services.google_maps.api_key' => '',
        ]);
    }

    private function user(): User
    {
        return User::create([
            'email' => 'map-directions@example.com',
            'display_name' => 'Map User',
            'password' => Hash::make('password'),
            'role' => UserRole::SuperAdmin,
        ]);
    }

    public function test_map_page_uses_routes_api_when_configured(): void
    {
        app(GoogleRoutesConfigService::class)->save(true, ['api_key' => 'AIzaSyMapRoutesKey00000000000000']);

        $this->actingAs($this->user())->get('/map')
            ->assertOk()
            ->assertSee('hasRoutesApi: true', false)
            ->assertSee('map-route-results', false);
    }

    public function test_directions_uses_routes_api_for_transit(): void
    {
        app(GoogleRoutesConfigService::class)->save(true, ['api_key' => 'AIzaSyMapRoutesKey00000000000000']);
        Http::fake([
            'routes.googleapis.com/*' => Http::response([
                'routes' => [[
                    'duration' => '1500s',
                    'distanceMeters' => 4200,
                    'polyline' => ['encodedPolyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@'],
                    'legs' => [[
                        'steps' => [
                            [
                                'travelMode' => 'WALK',
                                'staticDuration' => '180s',
                                'polyline' => ['encodedPolyline' => '_p~iF~ps|U'],
                                'navigationInstruction' => ['instructions' => '天神まで歩く'],
                            ],
                            [
                                'travelMode' => 'TRANSIT',
                                'staticDuration' => '1320s',
                                'polyline' => ['encodedPolyline' => '_ulLnnqC_mqNvxq`@'],
                                'transitDetails' => [
                                    'stopDetails' => [
                                        'departureStop' => ['name' => '天神'],
                                        'arrivalStop' => ['name' => '博多'],
                                    ],
                                    'transitLine' => ['nameShort' => '空港線'],
                                ],
                            ],
                        ],
                    ]],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->user())
            ->postJson('/map/directions', [
                'originLabel' => '天神',
                'destinationLabel' => '博多',
                'travelMode' => 'transit',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('fallback', false)
            ->assertJsonPath('routes.0.steps.1.text', '空港線 天神 → 博多')
            ->assertJsonPath('routes.0.polylines.0.mode', 'WALK')
            ->assertJsonPath('steps.1.text', '空港線 天神 → 博多');

        Http::assertSent(function ($request) {
            $mask = $request->header('X-Goog-FieldMask')[0] ?? '';

            return str_contains($request->url(), 'computeRoutes')
                && $request['travelMode'] === 'TRANSIT'
                && str_contains($mask, 'encodedPolyline');
        });
    }

    public function test_directions_falls_back_when_routes_is_not_configured(): void
    {
        $this->actingAs($this->user())
            ->postJson('/map/directions', [
                'originLabel' => '天神',
                'destinationLabel' => '博多',
                'travelMode' => 'transit',
            ])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('fallback', true);

        Http::assertNothingSent();
    }

    public function test_directions_maps_driving_to_drive(): void
    {
        app(GoogleRoutesConfigService::class)->save(true, ['api_key' => 'AIzaSyMapRoutesKey00000000000000']);
        Http::fake([
            'routes.googleapis.com/*' => Http::response([
                'routes' => [[
                    'duration' => '600s',
                    'distanceMeters' => 3000,
                    'polyline' => ['encodedPolyline' => '_p~iF~ps|U'],
                    'legs' => [[
                        'steps' => [[
                            'travelMode' => 'DRIVE',
                            'navigationInstruction' => ['instructions' => '直進'],
                            'polyline' => ['encodedPolyline' => '_p~iF~ps|U'],
                        ]],
                    ]],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->user())
            ->postJson('/map/directions', [
                'originLabel' => '天神',
                'destinationLabel' => '博多',
                'travelMode' => 'driving',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(fn ($request) => ($request['travelMode'] ?? '') === 'DRIVE');
    }
}
