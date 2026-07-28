<?php

namespace App\Http\Controllers;

use App\Services\TravelFareQuoteService;
use App\Services\TravelFareTableService;
use App\Services\TravelPromoWatchService;
use App\Services\TravelService;
use Illuminate\Http\Request;

class TravelController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(
        private TravelService $travel,
        private TravelPromoWatchService $promoWatch,
        private TravelFareQuoteService $fareQuote,
        private TravelFareTableService $fareTable,
    ) {}

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;
        $profile = $this->travel->getOrCreateProfile($userId);
        $this->travel->checkAlertsForUser($userId, $profile);

        $tripDraft = $request->session()->get('travel.trip_draft');
        $fareTable = $request->session()->get('travel.fare_table');

        return view('travel.index', [
            'profile' => $this->travel->profileToArray($profile),
            'deadlines' => $this->travel->deadlineSummary($profile),
            'trips' => $this->travel->listTrips($userId),
            'promos' => $this->travel->listPromos($userId),
            'snapshots' => $this->travel->listFareSnapshots($userId),
            'alerts' => $this->travel->listAlerts($userId),
            'promoWatchSources' => TravelService::PROMO_WATCH_SOURCES,
            'tripDraft' => is_array($tripDraft) ? $tripDraft : null,
            'fareTable' => is_array($fareTable) ? $fareTable : null,
            'purposeLabels' => collect(TravelService::PURPOSES)
                ->map(fn (string $label) => __($label))
                ->all(),
            'statusLabels' => collect(TravelService::STATUSES)
                ->map(fn (string $label) => __($label))
                ->all(),
            'promoStatusLabels' => collect(TravelService::PROMO_STATUSES)
                ->map(fn (string $label) => __($label))
                ->all(),
            'promoAppliesLabels' => collect(TravelService::PROMO_APPLIES)
                ->map(fn (string $label) => __($label))
                ->all(),
            'returnTo' => '/travel',
            ...$this->flashFromQuery($request),
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
                'homeAirport' => $request->input('homeAirport'),
                'phAirport' => $request->input('phAirport'),
                'airlineCode' => $request->input('airlineCode'),
                'notes' => $request->input('notes'),
                'alertsEnabled' => $request->boolean('alertsEnabled'),
                'alertDaysRp' => $request->input('alertDaysRp'),
                'alertDaysAr' => $request->input('alertDaysAr'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('Travelプロフィールを保存しました。'));
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

            $quote = $this->fareQuote->quote(
                (string) $request->input('departOn'),
                $request->input('returnOn'),
                (string) ($request->input('origin') ?: $profile->home_airport ?: 'FUK'),
                (string) ($request->input('destination') ?: $profile->ph_airport ?: 'MNL'),
                (string) ($request->input('airlineCode') ?: $profile->airline_code ?: '5J'),
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
            'preferCurrency' => $profile->preferred_currency ?: 'PHP',
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
        $profile = $this->travel->getOrCreateProfile((int) $request->user()->id);

        try {
            $mode = $request->input('tableMode') === 'rt' ? 'rt' : 'ow';
            $table = $this->fareTable->build(
                $mode,
                (string) $request->input('departFrom'),
                (string) $request->input('departTo'),
                $mode === 'rt' ? (string) $request->input('returnFrom') : null,
                $mode === 'rt' ? (string) $request->input('returnTo') : null,
                (string) ($request->input('origin') ?: $profile->home_airport ?: 'FUK'),
                (string) ($request->input('destination') ?: $profile->ph_airport ?: 'MNL'),
                (string) ($request->input('airlineCode') ?: $profile->airline_code ?: '5J'),
                (string) ($request->input('tableCurrency') ?: $profile->preferred_currency ?: 'PHP'),
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

    /** @return array<string, mixed> */
    private function tripPayload(Request $request): array
    {
        return [
            'purpose' => $request->input('purpose'),
            'label' => $request->input('label'),
            'departOn' => $request->input('departOn'),
            'returnOn' => $request->input('returnOn'),
            'origin' => $request->input('origin'),
            'destination' => $request->input('destination'),
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
