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

    public function test_directions_uses_transit_search_for_public_transit(): void
    {
        $response = $this->actingAs($this->user())
            ->postJson('/map/directions', [
                'originLabel' => '天神',
                'destinationLabel' => '博多',
                'travelMode' => 'transit',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('fallback', false)
            ->assertJsonPath('engine', 'RAPTOR');
        $this->assertGreaterThan(0, count($response->json('routes') ?? []));
    }

    public function test_directions_falls_back_when_routes_is_not_configured(): void
    {
        $this->actingAs($this->user())
            ->postJson('/map/directions', [
                'originLabel' => '天神',
                'destinationLabel' => '博多',
                'travelMode' => 'driving',
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
