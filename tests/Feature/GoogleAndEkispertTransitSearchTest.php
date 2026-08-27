<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\GoogleRoutesConfigService;
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

    public function test_search_skips_google_routes_for_japan_transit(): void
    {
        app(GoogleRoutesConfigService::class)->save(true, ['api_key' => 'AIzaSyRoutesSearchKey0000000000']);
        Http::fake([
            'routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '1s']]], 200),
        ]);

        $response = $this->actingAs($this->user())->postJson('/transit/search', [
            'from' => '天神',
            'to' => '博多',
            'departureAt' => '2026-08-26T08:00',
            'engine' => 'google',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('engine', 'RAPTOR');
        $this->assertStringContainsString('日本の交通機関ルート', (string) $response->json('engineNote'));
        Http::assertNothingSent();
    }

    public function test_google_selection_falls_back_without_calling_routes_api(): void
    {
        app(GoogleRoutesConfigService::class)->save(true, ['api_key' => 'AIzaSyRoutesFailKey000000000000']);
        Http::fake();

        $response = $this->actingAs($this->user())->postJson('/transit/search', [
            'from' => '天神',
            'to' => '博多',
            'departureAt' => '2026-08-26T08:00',
            'engine' => 'google',
        ]);

        $response->assertOk()->assertJsonPath('engine', 'RAPTOR');
        $this->assertStringContainsString('Google Maps Routes', (string) $response->json('engineNote'));
        $this->assertStringContainsString('日本の交通機関ルート', (string) $response->json('engineNote'));
        Http::assertNothingSent();
    }

    public function test_auto_skips_google_routes_because_japan_transit_is_unsupported(): void
    {
        app(GoogleRoutesConfigService::class)->save(true, ['api_key' => 'AIzaSyRoutesAutoSkipKey000000000']);
        Http::fake([
            'routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '1s']]], 200),
        ]);

        $response = $this->actingAs($this->user())->postJson('/transit/search', [
            'from' => '天神',
            'to' => '博多',
            'departureAt' => '2026-08-26T08:00',
        ]);

        $response->assertOk()->assertJsonPath('engine', 'RAPTOR');
        $this->assertNull($response->json('engineNote'));
        Http::assertNothingSent();
    }

    public function test_empty_google_routes_explains_japan_is_unsupported(): void
    {
        app(GoogleRoutesConfigService::class)->save(true, ['api_key' => 'AIzaSyRoutesEmptyJapanKey00000000']);
        Http::fake();

        $response = $this->actingAs($this->user())->postJson('/transit/search', [
            'from' => '志賀島',
            'to' => '天神四丁目',
            'departureAt' => '2026-08-26T08:00',
            'engine' => 'google',
        ]);

        $response->assertOk()->assertJsonPath('engine', 'RAPTOR');
        $this->assertStringContainsString('日本の交通機関ルート', (string) $response->json('engineNote'));
        Http::assertNothingSent();
    }
}
