<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaStorageSetting;
use App\Models\User;
use App\Services\EkispertConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EkispertSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ekispert.api_key' => '',
            'services.ekispert.base_url' => 'https://api.ekispert.jp/v1/json',
        ]);
    }

    private function makeUser(UserRole $role, string $email): User
    {
        return User::create([
            'email' => $email,
            'display_name' => $role->label(),
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    public function test_api_settings_page_shows_ekispert_form_to_admin(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-ekispert-ui@example.com');

        $this->actingAs($admin)->get('/settings?section=enhance')
            ->assertOk()
            ->assertSee('駅すぱあと（路線検索の経路探索）', false)
            ->assertSee('ekispert-api-settings', false);
    }

    public function test_admin_can_save_ekispert_key(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-ekispert@example.com');

        $this->actingAs($admin)
            ->post('/settings/api/ekispert', [
                'enabled' => '1',
                'api_key' => 'ekispert-access-key-000',
                'base_url' => 'https://api.ekispert.jp/v1/json/',
            ])
            ->assertRedirect('/settings?section=enhance#ekispert-api-settings');

        $ekispert = app(EkispertConfigService::class);
        $this->assertTrue($ekispert->isReady());
        $this->assertSame('ekispert-access-key-000', $ekispert->apiKey());
        $this->assertSame('https://api.ekispert.jp/v1/json', $ekispert->baseUrl());
    }

    public function test_saved_key_is_kept_when_the_masked_value_is_posted_back(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-ekispert-mask@example.com');
        app(EkispertConfigService::class)->save(true, [], ['api_key' => 'keep-this-key']);

        $this->actingAs($admin)
            ->post('/settings/api/ekispert', [
                'enabled' => '1',
                'api_key' => '••••••••',
            ])
            ->assertRedirect();

        $this->assertSame('keep-this-key', app(EkispertConfigService::class)->apiKey());
    }

    public function test_connection_test_reports_success(): void
    {
        Http::fake([
            'api.ekispert.jp/*' => Http::response([
                'ResultSet' => [
                    'Course' => ['Route' => ['transferCount' => '0']],
                ],
            ], 200),
        ]);

        $admin = $this->makeUser(UserRole::SuperAdmin, 'super-ekispert-test@example.com');
        app(EkispertConfigService::class)->save(true, [], ['api_key' => 'ekispert-test-key']);

        $this->actingAs($admin)
            ->postJson('/settings/api/ekispert/test')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $row = MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_EKISPERT);
        $this->assertSame('ok', $row->last_test_status);
    }

    public function test_connection_test_explains_an_authentication_failure(): void
    {
        Http::fake([
            'api.ekispert.jp/*' => Http::response(['ResultSet' => ['Error' => ['Message' => 'invalid key']]], 403),
        ]);

        $admin = $this->makeUser(UserRole::SuperAdmin, 'super-ekispert-fail@example.com');
        app(EkispertConfigService::class)->save(true, [], ['api_key' => 'bad-key']);

        $this->actingAs($admin)
            ->postJson('/settings/api/ekispert/test')
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_disabled_settings_are_not_used(): void
    {
        app(EkispertConfigService::class)->save(true, [], ['api_key' => 'ekispert-hidden']);
        app(EkispertConfigService::class)->save(false, [], []);

        $this->assertFalse(app(EkispertConfigService::class)->isReady());
        $this->assertSame('', app(EkispertConfigService::class)->apiKey());
    }
}
