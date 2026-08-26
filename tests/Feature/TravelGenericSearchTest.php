<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\TravelProfile;
use App\Models\User;
use App\Services\TravelAirportSuggestService;
use App\Services\TravelPromoWatchService;
use App\Services\TravelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TravelGenericSearchTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::create([
            'email' => 'travel-admin@example.com',
            'display_name' => 'Travel Admin',
            'password' => Hash::make('password'),
            'role' => UserRole::SuperAdmin,
        ]);
    }

    public function test_new_profile_defaults_are_generic_and_procedures_are_hidden(): void
    {
        Http::fake(['autocomplete.travelpayouts.com/*' => Http::response([], 200)]);

        $user = $this->makeAdmin();
        $this->actingAs($user)->get('/travel')
            ->assertOk()
            ->assertSee(__('フライトを探す'), false)
            ->assertSee(__('区間・日付・航空会社からフライトの目安運賃を出します'), false)
            ->assertDontSee(__('手続き期限'), false)
            ->assertDontSee('Annual Report', false)
            ->assertDontSee(__('RP（再入国許可）'), false)
            ->assertDontSee(__('セール／プロモ監視'), false)
            ->assertDontSee(__('1/1〜2/28 にフィリピン現地で手続きが必要です。できれば1月中に完了を推奨。'), false)
            ->assertDontSee(__('Cebu Pacific は公開コードより Seat Sale が本体です'), false)
            ->assertDontSee(__('保存した検索'), false)
            ->assertDontSee(__('検索の既定値'), false)
            ->assertDontSee(__('フライトを残す'), false)
            ->assertDontSee(__('運賃スナップショット履歴'), false)
            ->assertSee(__('フライト予定'), false)
            ->assertSee('data-travel-airport', false);

        $profile = TravelProfile::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('JPY', $profile->preferred_currency);
        $this->assertFalse((bool) $profile->procedures_enabled);
        $this->assertFalse((bool) $profile->promo_watch_enabled);
        $this->assertSame('', (string) $profile->airline_code);
    }

    public function test_airport_alias_resolves_without_remote_hits(): void
    {
        Http::fake(['autocomplete.travelpayouts.com/*' => Http::response([], 200)]);
        $airports = app(TravelAirportSuggestService::class);

        $this->assertSame('FUK', $airports->resolveCode('福岡'));
        $this->assertSame('HND', $airports->resolveCode('羽田'));
        $this->assertSame('MNL', $airports->resolveCode('manila'));
    }

    public function test_fare_table_requires_airports(): void
    {
        $user = $this->makeAdmin();
        $this->actingAs($user)
            ->from('/travel')
            ->post('/travel/fares/table', [
                'tableMode' => 'ow',
                'departFrom' => now()->toDateString(),
                'departTo' => now()->addDays(7)->toDateString(),
                'tableCurrency' => 'JPY',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', __('出発空港と到着空港を入力してください。都市名でも IATA コードでも検索できます。'));
    }

    public function test_promo_cron_skips_users_without_opt_in(): void
    {
        $user = $this->makeAdmin();
        app(TravelService::class)->getOrCreateProfile((int) $user->id);

        $stats = app(TravelPromoWatchService::class)->fetchAndSyncForAllUsers();
        $this->assertSame(0, $stats['users']);
    }

    public function test_standard_user_cannot_open_travel(): void
    {
        $user = User::create([
            'email' => 'travel-standard@example.com',
            'display_name' => 'Standard',
            'password' => Hash::make('password'),
            'role' => UserRole::Standard,
        ]);

        $this->actingAs($user)->get('/travel')->assertForbidden();
    }

    public function test_airport_suggest_returns_local_alias(): void
    {
        Http::fake(['autocomplete.travelpayouts.com/*' => Http::response([], 200)]);
        $user = $this->makeAdmin();

        $this->actingAs($user)->get('/travel/airports/suggest?q='.rawurlencode('福岡'))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('items.0.code', 'FUK');
    }

    public function test_legacy_packs_stay_off_the_travel_screen(): void
    {
        Http::fake(['autocomplete.travelpayouts.com/*' => Http::response([], 200)]);
        $user = $this->makeAdmin();
        $this->actingAs($user)->get('/travel')->assertOk();

        TravelProfile::query()->where('user_id', $user->id)->update([
            'procedures_enabled' => true,
            'promo_watch_enabled' => true,
            'rp_expires_on' => '2026-11-09',
            'annual_report_done_year' => 2026,
            'visa_type' => '13A',
            'airline_code' => '5J',
        ]);

        $this->actingAs($user)->get('/travel')
            ->assertOk()
            ->assertDontSee(__('手続き期限'), false)
            ->assertDontSee('Annual Report', false)
            ->assertDontSee(__('RP（再入国許可）'), false)
            ->assertDontSee(__('セール／プロモ監視'), false)
            ->assertDontSee('The Poor Traveler', false)
            ->assertSee(__('フライトを探す'), false)
            ->assertSee(__('航空会社（任意）'), false)
            ->assertDontSee('value="5J"', false)
            ->assertDontSee(__('検索の既定値'), false)
            ->assertDontSee(__('フライトを残す'), false);
    }

    public function test_candidate_flight_can_be_shown_as_planned(): void
    {
        Http::fake(['autocomplete.travelpayouts.com/*' => Http::response([], 200)]);
        $user = $this->makeAdmin();
        $departOn = now()->toDateString();

        $this->actingAs($user)->from('/travel')->post('/travel/fares/select', [
            'origin' => 'FUK',
            'destination' => 'MNL',
            'mode' => 'ow',
            'airline' => 'PR',
            'departOn' => $departOn,
            'currency' => 'JPY',
            'priceJpy' => 18000,
        ])->assertRedirect()->assertSessionHas('notice');

        $selected = session('travel.selected_flights');
        $this->assertIsArray($selected);
        $this->assertCount(1, $selected);
        $this->assertSame('PR', $selected[0]['airline']);
        $this->assertSame('FUK', $selected[0]['origin']);

        $this->actingAs($user)->get('/travel')
            ->assertOk()
            ->assertSee('Philippine Airlines', false)
            ->assertSee($departOn, false);
    }

    public function test_fare_table_keeps_airlines_other_than_5j(): void
    {
        config(['services.travelpayouts.token' => 'test-token']);
        $d1 = now()->toDateString();
        $d2 = now()->addDays(3)->toDateString();

        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($d1, $d2) {
            if (str_contains($request->url(), 'prices_for_dates')) {
                return Http::response([
                    'success' => true,
                    'data' => [
                        ['price' => 12000, 'departure_at' => $d1.'T00:00:00', 'airline' => '5J'],
                        ['price' => 18000, 'departure_at' => $d1.'T00:00:00', 'airline' => 'PR'],
                        ['price' => 11000, 'departure_at' => $d2.'T00:00:00', 'airline' => '5J'],
                        ['price' => 16000, 'departure_at' => $d2.'T00:00:00', 'airline' => 'JL'],
                    ],
                ]);
            }

            return Http::response(['success' => true, 'data' => []], 200);
        });

        $user = $this->makeAdmin();
        $this->actingAs($user)->get('/travel')->assertOk();
        TravelProfile::query()->where('user_id', $user->id)->update(['airline_code' => '5J']);

        $this->actingAs($user)->from('/travel')->post('/travel/fares/table', [
            'origin' => 'FUK',
            'destination' => 'MNL',
            'tableMode' => 'ow',
            'departFrom' => $d1,
            'departTo' => $d2,
            'tableCurrency' => 'JPY',
            'airlineCode' => '',
        ])->assertRedirect()->assertSessionHas('notice');

        $table = session('travel.fare_table');
        $this->assertIsArray($table);
        $this->assertSame('', (string) ($table['airlineCode'] ?? 'missing'));
        $airlines = collect($table['cheapest'] ?? [])
            ->pluck('airline')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $this->assertContains('PR', $airlines);
        $this->assertContains('JL', $airlines);

        $this->actingAs($user)->get('/travel')
            ->assertOk()
            ->assertSee('Philippine Airlines', false)
            ->assertSee('JAL', false)
            ->assertSee(__('表示する'), false);
    }

    public function test_ai_extracts_city_pair_from_prompt(): void
    {
        Http::fake(['autocomplete.travelpayouts.com/*' => Http::response([], 200)]);
        $pair = app(\App\Services\TravelAiConsultService::class)
            ->extractPair('福岡から羽田は、いつ買うと安いですか？');

        $this->assertSame(['FUK', 'HND'], $pair);
    }
}
