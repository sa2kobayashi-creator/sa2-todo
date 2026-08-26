<?php

namespace App\Http\Controllers;

use App\Services\TravelAirportSuggestService;
use App\Services\TravelFareQuoteService;
use App\Services\TravelFareTableService;
use App\Services\TravelFareWatchService;
use App\Services\TravelPromoWatchService;
use App\Services\TravelService;
use App\Services\WorkersAiGuideService;
use App\Support\AirlineName;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TravelController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(
        private TravelService $travel,
        private TravelPromoWatchService $promoWatch,
        private TravelFareQuoteService $fareQuote,
        private TravelFareTableService $fareTable,
        private TravelAirportSuggestService $airports,
        private TravelFareWatchService $watches,
        private WorkersAiGuideService $guide,
    ) {}

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;
        $this->travel->getOrCreateProfile($userId);

        $fareTable = $request->session()->get('travel.fare_table');
        $selectedFlights = $request->session()->get('travel.selected_flights', []);
        $today = now();

        return view('travel.index', [
            'fareTable' => is_array($fareTable) ? $fareTable : null,
            'selectedFlights' => is_array($selectedFlights) ? array_values($selectedFlights) : [],
            'searchDefaults' => [
                'origin' => is_array($fareTable) ? ($fareTable['origin'] ?? '') : '',
                'destination' => is_array($fareTable) ? ($fareTable['destination'] ?? '') : '',
                'airlineCode' => is_array($fareTable) ? ($fareTable['airlineCode'] ?? '') : '',
                'currency' => is_array($fareTable) ? ($fareTable['currency'] ?? 'JPY') : 'JPY',
                'departFrom' => is_array($fareTable) ? ($fareTable['departFrom'] ?? '') : $today->toDateString(),
                'departTo' => is_array($fareTable) ? ($fareTable['departTo'] ?? '') : $today->copy()->addDays(29)->toDateString(),
                'returnFrom' => is_array($fareTable) ? ($fareTable['returnFrom'] ?? '') : $today->copy()->addDays(7)->toDateString(),
                'returnTo' => is_array($fareTable) ? ($fareTable['returnTo'] ?? '') : $today->copy()->addDays(36)->toDateString(),
                'mode' => is_array($fareTable) ? ($fareTable['mode'] ?? 'ow') : 'ow',
            ],
            'returnTo' => '/travel',
            'aiTopic' => $this->guide->embeddedTopics()[WorkersAiGuideService::TOPIC_TRAVEL],
            'aiReady' => $this->guide->isReady(),
            ...$this->flashFromQuery($request),
        ]);
    }

    public function suggestAirports(Request $request)
    {
        $term = trim((string) $request->query('q', ''));
        $locale = app()->getLocale() === 'en' ? 'en' : 'ja';

        return response()->json([
            'ok' => true,
            'items' => $this->airports->suggest($term, $locale),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/travel');

        try {
            $this->travel->updateProfile((int) $request->user()->id, [
                'visaType' => $request->input('visaType'),
                'rpExpiresOn' => $request->input('rpExpiresOn'),
                'rpDurationMonths' => $request->input('rpDurationMonths'),
                'annualReportDoneYear' => $request->input('annualReportDoneYear'),
                'budgetMaxJpy' => $request->input('budgetMaxJpy'),
                'preferredCurrency' => $request->input('preferredCurrency'),
                'homeAirport' => $this->airports->resolveCode((string) $request->input('homeAirport', '')),
                'phAirport' => $this->airports->resolveCode((string) $request->input('phAirport', '')),
                'airlineCode' => $request->input('airlineCode'),
                'notes' => $request->input('notes'),
                'alertsEnabled' => $request->boolean('alertsEnabled'),
                'alertDaysRp' => $request->input('alertDaysRp'),
                'alertDaysAr' => $request->input('alertDaysAr'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('航空プロフィールを保存しました。'));
    }

    public function quoteTrip(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/travel');
        $profile = $this->travel->getOrCreateProfile((int) $request->user()->id);

        try {
            $bookedAs = $request->input('bookedAs');
            if (! in_array($bookedAs, ['rt', 'ow_pair'], true)) {
                throw new \InvalidArgumentException(__('予約形態（往復 / 片道×2）を選択してください。'));
            }
            if ($bookedAs === 'rt' && trim((string) $request->input('returnOn')) === '') {
                throw new \InvalidArgumentException(__('往復（RT）の場合は帰国日も入力してください。'));
            }

            [$origin, $destination, $airline] = $this->searchAirports($request);
            $quote = $this->fareQuote->quote(
                (string) $request->input('departOn'),
                $request->input('returnOn'),
                $origin,
                $destination,
                $airline,
                $bookedAs,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        } catch (\RuntimeException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        $draft = [
            'purpose' => $request->input('purpose', 'other'),
            'label' => (string) $request->input('label', ''),
            'status' => $request->input('status', 'planned'),
            'bookedAs' => $bookedAs,
            'preferCurrency' => $profile->preferred_currency ?: 'JPY',
            'quote' => $quote,
        ];
        $request->session()->put('travel.trip_draft', $draft);

        return $this->redirectWithMessage($returnTo, __('運賃見積もりを取得しました。内容を確認して追加してください。'));
    }

    public function clearTripDraft(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/travel');
        $request->session()->forget('travel.trip_draft');

        return $this->redirectWithMessage($returnTo, __('見積もりを破棄しました。'));
    }

    public function fareTable(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/travel');

        try {
            $mode = $request->input('tableMode') === 'rt' ? 'rt' : 'ow';
            [$origin, $destination, $airline] = $this->searchAirports($request);
            $table = $this->fareTable->build(
                $mode,
                (string) $request->input('departFrom'),
                (string) $request->input('departTo'),
                $mode === 'rt' ? (string) $request->input('returnFrom') : null,
                $mode === 'rt' ? (string) $request->input('returnTo') : null,
                $origin,
                $destination,
                $airline,
                (string) ($request->input('tableCurrency') ?: 'JPY'),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        } catch (\RuntimeException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        $request->session()->put('travel.fare_table', $table);

        return $this->redirectWithMessage($returnTo.'#travel-fare-table', __('料金表を取得しました。'));
    }

    public function clearFareTable(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/travel');
        $request->session()->forget('travel.fare_table');

        return $this->redirectWithMessage($returnTo, __('料金表をクリアしました。'));
    }

    public function selectFare(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/travel');

        try {
            $item = $this->selectedFareFromRequest($request);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        $list = $request->session()->get('travel.selected_flights', []);
        if (! is_array($list)) {
            $list = [];
        }
        $dupKey = $this->selectedFareKey($item);
        foreach ($list as $existing) {
            if (is_array($existing) && $this->selectedFareKey($existing) === $dupKey) {
                return $this->redirectWithMessage($returnTo.'#travel-planned-flights', __('すでにフライト予定に出しています。'));
            }
        }
        $list[] = $item;
        if (count($list) > 10) {
            $list = array_slice($list, -10);
        }
        $request->session()->put('travel.selected_flights', array_values($list));

        return $this->redirectWithMessage($returnTo.'#travel-planned-flights', __('候補をフライト予定に出しました。'));
    }

    public function removeSelectedFare(Request $request, string $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/travel');
        $list = $request->session()->get('travel.selected_flights', []);
        if (! is_array($list)) {
            $list = [];
        }
        $list = array_values(array_filter(
            $list,
            fn ($row) => ! is_array($row) || (string) ($row['id'] ?? '') !== $id
        ));
        $request->session()->put('travel.selected_flights', $list);

        return $this->redirectWithMessage($returnTo.'#travel-planned-flights', __('フライト予定から外しました。'));
    }

    public function clearSelectedFares(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/travel');
        $request->session()->forget('travel.selected_flights');

        return $this->redirectWithMessage($returnTo.'#travel-planned-flights', __('フライト予定をクリアしました。'));
    }

    public function storeWatch(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/travel');

        try {
            $this->watches->create((int) $request->user()->id, [
                'origin' => $request->input('origin'),
                'destination' => $request->input('destination'),
                'airlineCode' => $request->input('airlineCode'),
                'mode' => $request->input('mode'),
                'currency' => $request->input('currency'),
                'departFrom' => $request->input('departFrom'),
                'departTo' => $request->input('departTo'),
                'returnFrom' => $request->input('returnFrom'),
                'returnTo' => $request->input('returnTo'),
                'maxPrice' => $request->input('maxPrice'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo.'#travel-fare-watches', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo.'#travel-fare-watches', __('この検索条件を保存しました。値下がりがあればお知らせします。'));
    }

    public function destroyWatch(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/travel');
        $ok = $this->watches->delete((int) $request->user()->id, $id);
        if (! $ok) {
            return $this->redirectWithMessage($returnTo.'#travel-fare-watches', __('保存した検索が見つかりません。'), 'error');
        }

        return $this->redirectWithMessage($returnTo.'#travel-fare-watches', __('保存した検索を削除しました。'));
    }

    public function checkWatch(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/travel');

        try {
            $created = $this->watches->checkForUser((int) $request->user()->id, $id);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo.'#travel-fare-watches', $e->getMessage(), 'error');
        } catch (\RuntimeException $e) {
            return $this->redirectWithMessage($returnTo.'#travel-fare-watches', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage(
            $returnTo.'#travel-fare-watches',
            $created > 0
                ? __('料金を確認し、新しいお知らせを :n 件作成しました。', ['n' => $created])
                : __('料金を確認しました。新しいお知らせはありません。')
        );
    }

    public function storeTrip(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/travel');

        try {
            $this->travel->createTrip((int) $request->user()->id, $this->tripPayload($request));
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        $request->session()->forget('travel.trip_draft');

        return $this->redirectWithMessage($returnTo, __('渡航予定を追加しました。'));
    }

    public function updateTrip(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/travel');

        try {
            $updated = $this->travel->updateTrip((int) $request->user()->id, $id, $this->tripPayload($request));
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        if (! $updated) {
            return $this->redirectWithMessage($returnTo, __('渡航予定が見つかりません。'), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('渡航予定を更新しました。'));
    }

    public function destroyTrip(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/travel');
        $ok = $this->travel->deleteTrip((int) $request->user()->id, $id);
        if (! $ok) {
            return $this->redirectWithMessage($returnTo, __('渡航予定が見つかりません。'), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('渡航予定を削除しました。'));
    }

    public function fetchPromos(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/travel');
        $profile = $this->travel->getOrCreateProfile((int) $request->user()->id);
        if (! ($profile->promo_watch_enabled ?? false)) {
            return $this->redirectWithMessage($returnTo, __('セール監視がオフです。プロフィールで有効にしてください。'), 'error');
        }
        $result = $this->promoWatch->fetchAndSyncForUser((int) $request->user()->id);

        if ($result['errors'] !== []) {
            return $this->redirectWithMessage($returnTo, $result['errors'][0], 'error');
        }

        return $this->redirectWithMessage(
            $returnTo,
            __('セール情報を取得しました（新規 :created / 更新 :updated）。', [
                'created' => $result['created'],
                'updated' => $result['updated'],
            ])
        );
    }

    public function storePromo(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/travel');

        try {
            $this->travel->createPromo((int) $request->user()->id, $this->promoPayload($request));
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('プロモを追加しました。'));
    }

    public function updatePromo(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/travel');

        try {
            $updated = $this->travel->updatePromo((int) $request->user()->id, $id, $this->promoPayload($request));
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        if (! $updated) {
            return $this->redirectWithMessage($returnTo, __('プロモが見つかりません。'), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('プロモを更新しました。'));
    }

    public function destroyPromo(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/travel');
        $ok = $this->travel->deletePromo((int) $request->user()->id, $id);
        if (! $ok) {
            return $this->redirectWithMessage($returnTo, __('プロモが見つかりません。'), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('プロモを削除しました。'));
    }

    public function markAlertRead(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/travel');
        $ok = $this->travel->markAlertRead((int) $request->user()->id, $id);
        if (! $ok) {
            return $this->redirectWithMessage($returnTo, __('アラートが見つかりません。'), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('アラートを既読にしました。'));
    }

    public function markAllAlertsRead(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/travel');
        $this->travel->markAllAlertsRead((int) $request->user()->id);

        return $this->redirectWithMessage($returnTo, __('すべてのアラートを既読にしました。'));
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function searchAirports(Request $request): array
    {
        $origin = $this->airports->resolveCode((string) $request->input('origin', ''));
        $destination = $this->airports->resolveCode((string) $request->input('destination', ''));
        $airline = strtoupper(trim((string) $request->input('airlineCode', '')));
        if ($origin === '' || $destination === '') {
            throw new \InvalidArgumentException(__('出発空港と到着空港を入力してください。都市名でも IATA コードでも検索できます。'));
        }

        return [$origin, $destination, $airline];
    }

    /** @return array<string, mixed> */
    private function selectedFareFromRequest(Request $request): array
    {
        $origin = $this->airports->resolveCode((string) $request->input('origin', ''));
        $destination = $this->airports->resolveCode((string) $request->input('destination', ''));
        $departOn = trim((string) $request->input('departOn', ''));
        if ($origin === '' || $destination === '' || $departOn === '') {
            throw new \InvalidArgumentException(__('表示するフライトを選べませんでした。'));
        }

        $airline = strtoupper(trim((string) $request->input('airline', '')));
        $mode = $request->input('mode') === 'rt' ? 'rt' : 'ow';
        $currency = strtoupper((string) $request->input('currency', 'JPY')) === 'PHP' ? 'PHP' : 'JPY';
        $priceJpy = $request->input('priceJpy');
        $pricePhp = $request->input('pricePhp');

        return [
            'id' => (string) Str::uuid(),
            'mode' => $mode,
            'origin' => $origin,
            'destination' => $destination,
            'airline' => $airline,
            'airlineLabel' => AirlineName::label($airline),
            'departOn' => $departOn,
            'returnOn' => trim((string) $request->input('returnOn', '')),
            'priceJpy' => $priceJpy !== null && $priceJpy !== '' ? (int) $priceJpy : null,
            'pricePhp' => $pricePhp !== null && $pricePhp !== '' ? (int) $pricePhp : null,
            'currency' => $currency,
            'searchUrl' => (string) $request->input('searchUrl', ''),
            'officialUrl' => (string) $request->input('officialUrl', ''),
            'officialLabel' => (string) $request->input('officialLabel', ''),
        ];
    }

    /** @param  array<string, mixed>  $item */
    private function selectedFareKey(array $item): string
    {
        return implode('|', [
            (string) ($item['origin'] ?? ''),
            (string) ($item['destination'] ?? ''),
            (string) ($item['departOn'] ?? ''),
            (string) ($item['returnOn'] ?? ''),
            (string) ($item['airline'] ?? ''),
            (string) ($item['mode'] ?? ''),
        ]);
    }

    /** @return array<string, mixed> */
    private function tripPayload(Request $request): array
    {
        return [
            'purpose' => $request->input('purpose'),
            'label' => $request->input('label'),
            'departOn' => $request->input('departOn'),
            'returnOn' => $request->input('returnOn'),
            'origin' => $this->airports->resolveCode((string) $request->input('origin', '')),
            'destination' => $this->airports->resolveCode((string) $request->input('destination', '')),
            'airlineCode' => $request->input('airlineCode'),
            'status' => $request->input('status'),
            'preferCurrency' => $request->input('preferCurrency'),
            'bookedAs' => $request->input('bookedAs'),
            'rtPricePhp' => $request->input('rtPricePhp'),
            'owOutPricePhp' => $request->input('owOutPricePhp'),
            'owBackPricePhp' => $request->input('owBackPricePhp'),
            'rtPriceJpy' => $request->input('rtPriceJpy'),
            'owOutPriceJpy' => $request->input('owOutPriceJpy'),
            'owBackPriceJpy' => $request->input('owBackPriceJpy'),
            'outBookedInPhp' => $request->boolean('outBookedInPhp'),
            'backBookedInPhp' => $request->boolean('backBookedInPhp'),
            'notes' => $request->input('notes'),
        ];
    }

    /** @return array<string, mixed> */
    private function promoPayload(Request $request): array
    {
        return [
            'code' => $request->input('code'),
            'title' => $request->input('title'),
            'sourceUrl' => $request->input('sourceUrl'),
            'appliesTo' => $request->input('appliesTo'),
            'status' => $request->input('status'),
            'validFrom' => $request->input('validFrom'),
            'validUntil' => $request->input('validUntil'),
            'notes' => $request->input('notes'),
        ];
    }
}
