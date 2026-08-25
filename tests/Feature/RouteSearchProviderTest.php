<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\GoogleMapsConfigService;
use App\Services\NavitimeConfigService;
use App\Services\Transit\RouteSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RouteSearchProviderTest extends TestCase
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
            'services.navitime.node_host' => '',
            'services.google_maps.api_key' => '',
            'services.google_routes.api_key' => '',
            'services.ekispert.api_key' => '',
        ]);
    }

    private function user(): User
    {
        return User::create([
            'email' => 'route-provider@example.com',
            'display_name' => 'Route User',
            'password' => Hash::make('password'),
            'role' => UserRole::SuperAdmin,
        ]);
    }

    private function enableNavitime(): void
    {
        app(NavitimeConfigService::class)->save(true, ['mode' => 'rapidapi'], ['api_key' => 'rapid-key']);
        app(GoogleMapsConfigService::class)->saveConfig(true, ['api_key' => 'AIzaSyForGeocodingInTests00000000']);
    }

    public function test_providers_are_listed_with_the_builtin_engine_always_ready(): void
    {
        $service = app(RouteSearchService::class);

        $this->assertSame(['google', 'navitime', 'ekispert', 'raptor'], array_keys($service->all()));
        $this->assertFalse($service->all()['google']->isReady());
        $this->assertFalse($service->all()['navitime']->isReady());
        $this->assertFalse($service->all()['ekispert']->isReady());
        $this->assertTrue($service->all()['raptor']->isReady());
        $this->assertSame('RAPTOR', $service->activeProvider()?->label());
    }

    public function test_env_default_selects_the_provider(): void
    {
        config(['transit.provider' => 'raptor']);
        $this->assertSame('raptor', app(RouteSearchService::class)->selectedKey());

        config(['transit.provider' => 'unknown-engine']);
        $this->assertSame('auto', app(RouteSearchService::class)->selectedKey(), '知らないキーは auto に倒す');
    }

    public function test_saved_choice_wins_over_the_env_default(): void
    {
        config(['transit.provider' => 'auto']);
        app(RouteSearchService::class)->saveSelectedKey('raptor');

        $this->assertSame('raptor', app(RouteSearchService::class)->selectedKey());
    }

    public function test_settings_screen_saves_the_choice(): void
    {
        $this->actingAs($this->user())
            ->post('/settings/api/route-search', ['engine' => 'raptor'])
            ->assertRedirect();

        $this->assertSame('raptor', app(RouteSearchService::class)->selectedKey());
    }

    public function test_choosing_the_builtin_engine_skips_navitime_entirely(): void
    {
        $this->enableNavitime();
        app(RouteSearchService::class)->saveSelectedKey('raptor');
        Http::fake();

        $response = $this->actingAs($this->user())->postJson('/transit/search', [
            'from' => '天神',
            'to' => '博多',
            'departureAt' => '2026-08-25T08:00',
        ]);

        $response->assertOk()->assertJsonPath('engine', 'RAPTOR');
        Http::assertNothingSent();
    }

    public function test_transit_page_names_the_engine_in_use(): void
    {
        $this->enableNavitime();

        $this->actingAs($this->user())
            ->get('/transit')
            ->assertOk()
            ->assertSee('NAVITIME の時刻表で経路', false);
    }
}
