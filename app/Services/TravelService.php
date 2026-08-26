<?php

namespace App\Services;

use App\Models\TravelAlert;
use App\Models\TravelFareSnapshot;
use App\Models\TravelProfile;
use App\Models\TravelPromo;
use App\Models\TravelTrip;
use Carbon\Carbon;

class TravelService
{
    public const PURPOSES = [
        'annual_report' => 'Annual Report',
        'rp_renewal' => 'RP更新',
        'other' => 'その他',
    ];

    public const STATUSES = [
        'planned' => '予定',
        'booked' => '予約済',
        'done' => '完了',
        'cancelled' => '取消',
    ];

    public const PROMO_STATUSES = [
        'watching' => '監視中',
        'usable' => '利用可',
        'used' => '使用済',
        'invalid' => '無効コード',
        'expired' => '期限切れ',
    ];

    public const PROMO_APPLIES = [
        'both' => '両方',
        'fuk_mnl' => 'FUK→MNL',
        'mnl_fuk' => 'MNL→FUK',
        'unknown' => '不明',
    ];

    /** 監視・取得ソース（自動取得は公開まとめ記事） */
    public const PROMO_WATCH_SOURCES = [
        [
            'name' => 'The Poor Traveler (CEB promos)',
            'url' => 'https://www.thepoortraveler.net/cebu-pacific-promo/',
            'auto' => true,
        ],
        [
            'name' => 'Cebu Pacific Seat Sale（公式・確認用）',
            'url' => 'https://www.cebupacificair.com/en-PH/seat-sale',
            'auto' => false,
        ],
        [
            'name' => 'Cebu Pacific Deals（公式・確認用）',
            'url' => 'https://www.cebupacificair.com/en-PH/pages/deals-and-offers',
            'auto' => false,
        ],
    ];

    public function getOrCreateProfile(int $userId): TravelProfile
    {
        return TravelProfile::query()->firstOrCreate(
            ['user_id' => $userId],
            [
                'visa_type' => '',
                'rp_duration_months' => 6,
                'budget_max_jpy' => 60000,
                'preferred_currency' => 'JPY',
                'home_airport' => '',
                'ph_airport' => '',
                'airline_code' => '',
                'procedures_enabled' => false,
                'promo_watch_enabled' => false,
            ]
        );
    }

    /** @return array<string, mixed> */
    public function profileToArray(TravelProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'visaType' => (string) $profile->visa_type,
            'rpExpiresOn' => $profile->rp_expires_on?->format('Y-m-d'),
            'rpDurationMonths' => (int) $profile->rp_duration_months,
            'annualReportDoneYear' => $profile->annual_report_done_year,
            'budgetMaxJpy' => (int) $profile->budget_max_jpy,
            'preferredCurrency' => (string) $profile->preferred_currency,
            'homeAirport' => (string) $profile->home_airport,
            'phAirport' => (string) $profile->ph_airport,
            'airlineCode' => (string) $profile->airline_code,
            'notes' => (string) ($profile->notes ?? ''),
            'alertsEnabled' => (bool) ($profile->alerts_enabled ?? true),
            'alertDaysRp' => (int) ($profile->alert_days_rp ?? 90),
            'alertDaysAr' => (int) ($profile->alert_days_ar ?? 60),
            'proceduresEnabled' => (bool) ($profile->procedures_enabled ?? false),
            'promoWatchEnabled' => (bool) ($profile->promo_watch_enabled ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateProfile(int $userId, array $payload): TravelProfile
    {
        $profile = $this->getOrCreateProfile($userId);

        $currency = strtoupper(trim((string) ($payload['preferredCurrency'] ?? $profile->preferred_currency)));
        if (! in_array($currency, ['PHP', 'JPY'], true)) {
            $currency = 'JPY';
        }

        $profile->fill([
            'budget_max_jpy' => max(1, (int) ($payload['budgetMaxJpy'] ?? $profile->budget_max_jpy)),
            'preferred_currency' => $currency,
            'home_airport' => strtoupper(trim((string) ($payload['homeAirport'] ?? $profile->home_airport))),
            'ph_airport' => strtoupper(trim((string) ($payload['phAirport'] ?? $profile->ph_airport))),
            'airline_code' => strtoupper(trim((string) ($payload['airlineCode'] ?? $profile->airline_code))),
            'notes' => trim((string) ($payload['notes'] ?? '')),
            'alerts_enabled' => (bool) ($payload['alertsEnabled'] ?? $profile->alerts_enabled ?? true),
            'procedures_enabled' => false,
            'promo_watch_enabled' => false,
        ]);
        $profile->save();

        return $profile->fresh() ?? $profile;
    }

    /** @return list<array<string, mixed>> */
    public function listTrips(int $userId): array
    {
        return TravelTrip::query()
            ->where('user_id', $userId)
            ->orderBy('depart_on')
            ->orderBy('id')
            ->get()
            ->map(fn (TravelTrip $trip) => $this->tripToArray($trip))
            ->all();
    }

    /** @return array<string, mixed> */
    public function tripToArray(TravelTrip $trip): array
    {
        $owPhpSum = null;
        if ($trip->ow_out_price_php !== null || $trip->ow_back_price_php !== null) {
            $owPhpSum = (int) ($trip->ow_out_price_php ?? 0) + (int) ($trip->ow_back_price_php ?? 0);
        }
        $owJpySum = null;
        if ($trip->ow_out_price_jpy !== null || $trip->ow_back_price_jpy !== null) {
            $owJpySum = (int) ($trip->ow_out_price_jpy ?? 0) + (int) ($trip->ow_back_price_jpy ?? 0);
        }

        $comparePhp = $this->compareFares(
            $trip->rt_price_php !== null ? (int) $trip->rt_price_php : null,
            $owPhpSum
        );
        $compareJpy = $this->compareFares(
            $trip->rt_price_jpy !== null ? (int) $trip->rt_price_jpy : null,
            $owJpySum
        );

        return [
            'id' => $trip->id,
            'purpose' => (string) $trip->purpose,
            'purposeLabel' => __(self::PURPOSES[$trip->purpose] ?? $trip->purpose),
            'label' => (string) ($trip->label ?? ''),
            'departOn' => $trip->depart_on?->format('Y-m-d'),
            'returnOn' => $trip->return_on?->format('Y-m-d'),
            'origin' => (string) $trip->origin,
            'destination' => (string) $trip->destination,
            'airlineCode' => (string) $trip->airline_code,
            'status' => (string) $trip->status,
            'statusLabel' => __(self::STATUSES[$trip->status] ?? $trip->status),
            'preferCurrency' => (string) $trip->prefer_currency,
            'bookedAs' => $trip->booked_as,
            'rtPricePhp' => $trip->rt_price_php,
            'owOutPricePhp' => $trip->ow_out_price_php,
            'owBackPricePhp' => $trip->ow_back_price_php,
            'owSumPhp' => $owPhpSum,
            'rtPriceJpy' => $trip->rt_price_jpy,
            'owOutPriceJpy' => $trip->ow_out_price_jpy,
            'owBackPriceJpy' => $trip->ow_back_price_jpy,
            'owSumJpy' => $owJpySum,
            'outBookedInPhp' => (bool) $trip->out_booked_in_php,
            'backBookedInPhp' => (bool) $trip->back_booked_in_php,
            'comparePhp' => $comparePhp,
            'compareJpy' => $compareJpy,
            'notes' => (string) ($trip->notes ?? ''),
        ];
    }

    /**
     * @return array{winner: string|null, delta: int|null, label: string}
     */
    public function compareFares(?int $rt, ?int $owSum): array
    {
        if ($rt === null && $owSum === null) {
            return ['winner' => null, 'delta' => null, 'label' => __('未入力')];
        }
        if ($rt === null) {
            return ['winner' => 'ow', 'delta' => null, 'label' => __('片道×2のみ')];
        }
        if ($owSum === null) {
            return ['winner' => 'rt', 'delta' => null, 'label' => __('往復のみ')];
        }
        $delta = $rt - $owSum;
        if ($delta > 0) {
            return [
                'winner' => 'ow',
                'delta' => $delta,
                'label' => __('片道×2が :n 安い', ['n' => number_format($delta)]),
            ];
        }
        if ($delta < 0) {
            return [
                'winner' => 'rt',
                'delta' => abs($delta),
                'label' => __('往復が :n 安い', ['n' => number_format(abs($delta))]),
            ];
        }

        return ['winner' => 'tie', 'delta' => 0, 'label' => __('同額')];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createTrip(int $userId, array $payload): TravelTrip
    {
        $data = $this->normalizeTripPayload($payload);
        $maxSort = (int) TravelTrip::query()->where('user_id', $userId)->max('sort_order');

        $trip = TravelTrip::query()->create(array_merge($data, [
            'user_id' => $userId,
            'sort_order' => $maxSort + 1,
        ]));
        $this->recordFareSnapshot($userId, $trip);
        $this->maybeCreateBudgetAlert($userId, $trip);

        return $trip;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateTrip(int $userId, int $id, array $payload): ?TravelTrip
    {
        $trip = TravelTrip::query()->where('user_id', $userId)->where('id', $id)->first();
        if (! $trip) {
            return null;
        }
        $before = $trip->only([
            'rt_price_php', 'ow_out_price_php', 'ow_back_price_php',
            'rt_price_jpy', 'ow_out_price_jpy', 'ow_back_price_jpy',
        ]);
        $trip->fill($this->normalizeTripPayload($payload, $trip));
        $trip->save();
        $trip = $trip->fresh() ?? $trip;
        $after = $trip->only([
            'rt_price_php', 'ow_out_price_php', 'ow_back_price_php',
            'rt_price_jpy', 'ow_out_price_jpy', 'ow_back_price_jpy',
        ]);
        if ($before !== $after) {
            $this->recordFareSnapshot($userId, $trip);
            $this->maybeCreateBudgetAlert($userId, $trip);
        }

        return $trip;
    }

    public function deleteTrip(int $userId, int $id): bool
    {
        $trip = TravelTrip::query()->where('user_id', $userId)->where('id', $id)->first();
        if (! $trip) {
            return false;
        }
        $trip->delete();

        return true;
    }

    /**
     * @return array{
     *   timezone: string,
     *   today: string,
     *   annualReport: array<string, mixed>,
     *   rp: array<string, mixed>,
     *   nextDeadline: array<string, mixed>|null,
     *   tips: list<string>
     * }
     */
    public function deadlineSummary(TravelProfile $profile): array
    {
        $tz = config('app.timezone', 'Asia/Tokyo');
        $today = Carbon::now($tz)->startOfDay();
        $year = (int) $today->format('Y');
        $doneYear = (int) ($profile->annual_report_done_year ?? 0);
        $thisYearDeadline = Carbon::create($year, 2, 28, 0, 0, 0, $tz)->startOfDay();

        if ($doneYear >= $year) {
            $arDeadline = Carbon::create($year + 1, 2, 28, 0, 0, 0, $tz)->startOfDay();
            $arDoneThisYear = true;
        } else {
            $arDeadline = $thisYearDeadline;
            $arDoneThisYear = false;
        }

        $arDays = (int) $today->diffInDays($arDeadline, false);
        $annualReport = [
            'label' => __('Annual Report'),
            'deadline' => $arDeadline->format('Y-m-d'),
            'daysLeft' => $arDays,
            'doneThisYear' => $arDoneThisYear,
            'warn' => $arDays <= 60,
            'danger' => $arDays <= 30,
            'mustBeInPh' => true,
            'hint' => __('1/1〜2/28 にフィリピン現地で手続きが必要です。できれば1月中に完了を推奨。'),
        ];

        $rp = [
            'label' => __('RP（再入国許可）'),
            'deadline' => $profile->rp_expires_on?->format('Y-m-d'),
            'daysLeft' => null,
            'durationMonths' => (int) $profile->rp_duration_months,
            'warn' => false,
            'danger' => false,
            'hint' => __('有効期限切れ前にフィリピンへ戻る必要があります。'),
        ];
        if ($profile->rp_expires_on) {
            $rpDays = $today->diffInDays($profile->rp_expires_on->copy()->startOfDay(), false);
            $rp['daysLeft'] = (int) $rpDays;
            $rp['warn'] = $rpDays <= 90;
            $rp['danger'] = $rpDays <= 30;
        }

        $candidates = collect([$annualReport]);
        if ($rp['deadline']) {
            $candidates->push($rp);
        }
        $next = $candidates
            ->filter(fn ($d) => ($d['daysLeft'] ?? null) !== null)
            ->sortBy('daysLeft')
            ->first();

        $tips = [
            __('運賃は目安です。予約前に航空会社または比較サイトで税込金額を確認してください。'),
            __('片道と往復で税込を比較すると、安い組み合わせが見つかることがあります。'),
        ];
        if (($profile->procedures_enabled ?? false) && $next && ($next['label'] ?? '') === __('Annual Report') && ($next['daysLeft'] ?? 999) <= 90) {
            array_unshift($tips, __('次の必須渡航は Annual Report です。1月到着を優先してください。'));
        } elseif (($profile->procedures_enabled ?? false) && $next && ($next['daysLeft'] ?? 999) <= 90) {
            array_unshift($tips, __('次の必須渡航は RP 期限です。切れ前に帰国便を確保してください。'));
        }

        return [
            'timezone' => $tz,
            'today' => $today->format('Y-m-d'),
            'annualReport' => $annualReport,
            'rp' => $rp,
            'nextDeadline' => $next,
            'tips' => $tips,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeTripPayload(array $payload, ?TravelTrip $existing = null): array
    {
        $depart = trim((string) ($payload['departOn'] ?? $existing?->depart_on?->format('Y-m-d') ?? ''));
        if ($depart === '') {
            throw new \InvalidArgumentException(__('出発日は必須です。'));
        }

        $purpose = (string) ($payload['purpose'] ?? $existing?->purpose ?? 'other');
        if (! array_key_exists($purpose, self::PURPOSES)) {
            $purpose = 'other';
        }
        $status = (string) ($payload['status'] ?? $existing?->status ?? 'planned');
        if (! array_key_exists($status, self::STATUSES)) {
            $status = 'planned';
        }
        $currency = strtoupper(trim((string) ($payload['preferCurrency'] ?? $existing?->prefer_currency ?? 'JPY')));
        if (! in_array($currency, ['PHP', 'JPY'], true)) {
            $currency = 'JPY';
        }
        $bookedAs = $payload['bookedAs'] ?? $existing?->booked_as;
        $bookedAs = in_array($bookedAs, ['rt', 'ow_pair'], true) ? $bookedAs : null;

        $returnOn = trim((string) ($payload['returnOn'] ?? ''));
        if ($returnOn === '' && $existing?->return_on) {
            $returnOn = $existing->return_on->format('Y-m-d');
        }

        $origin = strtoupper(trim((string) ($payload['origin'] ?? $existing?->origin ?? '')));
        $destination = strtoupper(trim((string) ($payload['destination'] ?? $existing?->destination ?? '')));
        if ($origin === '' || $destination === '') {
            throw new \InvalidArgumentException(__('出発空港と到着空港は必須です。'));
        }

        return [
            'purpose' => $purpose,
            'label' => trim((string) ($payload['label'] ?? $existing?->label ?? '')) ?: null,
            'depart_on' => $depart,
            'return_on' => $returnOn !== '' ? $returnOn : null,
            'origin' => $origin,
            'destination' => $destination,
            'airline_code' => strtoupper(trim((string) ($payload['airlineCode'] ?? $existing?->airline_code ?? ''))),
            'status' => $status,
            'prefer_currency' => $currency,
            'booked_as' => $bookedAs,
            'rt_price_php' => $this->nullableUint($payload['rtPricePhp'] ?? $existing?->rt_price_php),
            'ow_out_price_php' => $this->nullableUint($payload['owOutPricePhp'] ?? $existing?->ow_out_price_php),
            'ow_back_price_php' => $this->nullableUint($payload['owBackPricePhp'] ?? $existing?->ow_back_price_php),
            'rt_price_jpy' => $this->nullableUint($payload['rtPriceJpy'] ?? $existing?->rt_price_jpy),
            'ow_out_price_jpy' => $this->nullableUint($payload['owOutPriceJpy'] ?? $existing?->ow_out_price_jpy),
            'ow_back_price_jpy' => $this->nullableUint($payload['owBackPriceJpy'] ?? $existing?->ow_back_price_jpy),
            'out_booked_in_php' => (bool) ($payload['outBookedInPhp'] ?? $existing?->out_booked_in_php ?? false),
            'back_booked_in_php' => (bool) ($payload['backBookedInPhp'] ?? $existing?->back_booked_in_php ?? false),
            'notes' => trim((string) ($payload['notes'] ?? $existing?->notes ?? '')) ?: null,
        ];
    }

    private function nullableUint(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }

    /** @return list<array<string, mixed>> */
    public function listPromos(int $userId): array
    {
        $this->expireStalePromos($userId);

        return TravelPromo::query()
            ->where('user_id', $userId)
            ->orderByRaw("CASE status WHEN 'usable' THEN 0 WHEN 'watching' THEN 1 WHEN 'used' THEN 2 ELSE 3 END")
            ->orderByDesc('valid_until')
            ->orderByDesc('id')
            ->get()
            ->map(fn (TravelPromo $promo) => $this->promoToArray($promo))
            ->all();
    }

    /** @return array<string, mixed> */
    public function promoToArray(TravelPromo $promo): array
    {
        return [
            'id' => $promo->id,
            'code' => (string) $promo->code,
            'title' => (string) ($promo->title ?? ''),
            'sourceUrl' => (string) ($promo->source_url ?? ''),
            'appliesTo' => (string) $promo->applies_to,
            'appliesToLabel' => __(self::PROMO_APPLIES[$promo->applies_to] ?? $promo->applies_to),
            'status' => (string) $promo->status,
            'statusLabel' => __(self::PROMO_STATUSES[$promo->status] ?? $promo->status),
            'validFrom' => $promo->valid_from?->format('Y-m-d'),
            'validUntil' => $promo->valid_until?->format('Y-m-d'),
            'travelFrom' => $promo->travel_from?->format('Y-m-d'),
            'travelUntil' => $promo->travel_until?->format('Y-m-d'),
            'notes' => (string) ($promo->notes ?? ''),
            'autoFetched' => (bool) ($promo->auto_fetched ?? false),
            'lastSeenAt' => $promo->last_seen_at?->format('Y-m-d H:i'),
            'externalKey' => (string) ($promo->external_key ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createPromo(int $userId, array $payload): TravelPromo
    {
        $data = $this->normalizePromoPayload($payload);

        return TravelPromo::query()->create(array_merge($data, ['user_id' => $userId]));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updatePromo(int $userId, int $id, array $payload): ?TravelPromo
    {
        $promo = TravelPromo::query()->where('user_id', $userId)->where('id', $id)->first();
        if (! $promo) {
            return null;
        }
        $promo->fill($this->normalizePromoPayload($payload, $promo));
        $promo->save();

        return $promo->fresh() ?? $promo;
    }

    public function deletePromo(int $userId, int $id): bool
    {
        $promo = TravelPromo::query()->where('user_id', $userId)->where('id', $id)->first();
        if (! $promo) {
            return false;
        }
        $promo->delete();

        return true;
    }

    /** @return list<array<string, mixed>> */
    public function listFareSnapshots(int $userId, ?int $tripId = null, int $limit = 40): array
    {
        $query = TravelFareSnapshot::query()
            ->where('travel_fare_snapshots.user_id', $userId)
            ->leftJoin('travel_trips', 'travel_trips.id', '=', 'travel_fare_snapshots.travel_trip_id')
            ->with('trip:id,label,purpose,depart_on,return_on')
            ->select('travel_fare_snapshots.*')
            ->orderByRaw('travel_trips.depart_on is null')
            ->orderBy('travel_trips.depart_on')
            ->orderByDesc('travel_fare_snapshots.captured_at')
            ->orderByDesc('travel_fare_snapshots.id')
            ->limit(max(1, $limit));

        if ($tripId !== null) {
            $query->where('travel_fare_snapshots.travel_trip_id', $tripId);
        }

        return $query->get()->map(function (TravelFareSnapshot $snap) {
            $trip = $snap->trip;
            $owPhp = null;
            if ($snap->ow_out_price_php !== null || $snap->ow_back_price_php !== null) {
                $owPhp = (int) ($snap->ow_out_price_php ?? 0) + (int) ($snap->ow_back_price_php ?? 0);
            }
            $owJpy = null;
            if ($snap->ow_out_price_jpy !== null || $snap->ow_back_price_jpy !== null) {
                $owJpy = (int) ($snap->ow_out_price_jpy ?? 0) + (int) ($snap->ow_back_price_jpy ?? 0);
            }

            return [
                'id' => $snap->id,
                'tripId' => $snap->travel_trip_id,
                'tripLabel' => $trip
                    ? (string) ($trip->label ?: __(self::PURPOSES[$trip->purpose] ?? $trip->purpose))
                    : __('（削除済）'),
                'departOn' => $trip?->depart_on?->format('Y-m-d'),
                'returnOn' => $trip?->return_on?->format('Y-m-d'),
                'rtPricePhp' => $snap->rt_price_php,
                'owSumPhp' => $owPhp,
                'rtPriceJpy' => $snap->rt_price_jpy,
                'owSumJpy' => $owJpy,
                'winnerPhp' => $snap->winner_php,
                'winnerJpy' => $snap->winner_jpy,
                'underBudgetJpy' => $snap->under_budget_jpy,
                'capturedAt' => $snap->captured_at?->format('Y-m-d H:i'),
            ];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    public function listAlerts(
        int $userId,
        bool $unreadOnly = false,
        int $limit = 30,
        ?string $type = null
    ): array {
        $query = TravelAlert::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, $limit));

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }
        if ($type !== null && $type !== '') {
            $query->where('type', $type);
        }

        return $query->get()->map(fn (TravelAlert $alert) => [
            'id' => $alert->id,
            'type' => (string) $alert->type,
            'severity' => (string) $alert->severity,
            'title' => (string) $alert->title,
            'body' => (string) ($alert->body ?? ''),
            'readAt' => $alert->read_at?->format('Y-m-d H:i'),
            'createdAt' => $alert->created_at?->format('Y-m-d H:i'),
            'isRead' => $alert->read_at !== null,
        ])->all();
    }

    public function markAlertRead(int $userId, int $id): bool
    {
        $alert = TravelAlert::query()->where('user_id', $userId)->where('id', $id)->first();
        if (! $alert) {
            return false;
        }
        if ($alert->read_at === null) {
            $alert->read_at = now();
            $alert->save();
        }

        return true;
    }

    public function markAllAlertsRead(int $userId): int
    {
        return TravelAlert::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * ダッシュボード用のコンパクト要約。
     *
     * @return array<string, mixed>
     */
    public function dashboardSummary(int $userId): array
    {
        $profile = $this->getOrCreateProfile($userId);
        $unreadFareAlerts = TravelAlert::query()
            ->where('user_id', $userId)
            ->whereIn('type', ['fare_drop', 'watch'])
            ->whereNull('read_at')
            ->count();

        $fareAlerts = $this->listAlerts($userId, true, 5, 'fare_drop');
        $watchAlerts = $this->listAlerts($userId, true, 5, 'watch');
        $fareAlerts = array_slice(array_merge($fareAlerts, $watchAlerts), 0, 5);

        return [
            'nextTrip' => $this->nextTripSummary($userId),
            'unreadAlerts' => $unreadFareAlerts,
            'unreadFareAlerts' => $unreadFareAlerts,
            'fareAlerts' => $fareAlerts,
            'budgetMaxJpy' => (int) $profile->budget_max_jpy,
            'alertsEnabled' => (bool) ($profile->alerts_enabled ?? true),
        ];
    }

    /** @return array<string, mixed>|null */
    public function nextTripSummary(int $userId): ?array
    {
        $trip = TravelTrip::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['planned', 'booked'])
            ->whereDate('depart_on', '>=', now()->toDateString())
            ->orderBy('depart_on')
            ->orderBy('id')
            ->first();
        if (! $trip) {
            return null;
        }

        return [
            'id' => $trip->id,
            'label' => (string) ($trip->label ?: __(self::PURPOSES[$trip->purpose] ?? $trip->purpose)),
            'origin' => (string) $trip->origin,
            'destination' => (string) $trip->destination,
            'airlineCode' => (string) ($trip->airline_code ?? ''),
            'departOn' => $trip->depart_on?->format('Y-m-d'),
            'returnOn' => $trip->return_on?->format('Y-m-d'),
            'statusLabel' => __(self::STATUSES[$trip->status] ?? $trip->status),
        ];
    }

    /**
     * 値下がり監視は TravelFareWatchService。期限パックのアラートは作らない。
     *
     * @return array{users: int, created: int}
     */
    public function checkAlertsForAllUsers(): array
    {
        return ['users' => 0, 'created' => 0];
    }

    public function checkAlertsForUser(int $userId, ?TravelProfile $profile = null): int
    {
        $profile ??= $this->getOrCreateProfile($userId);
        if (! ($profile->alerts_enabled ?? true)) {
            return 0;
        }

        return 0;
    }

    public function recordFareSnapshot(int $userId, TravelTrip $trip): void
    {
        $hasAny = collect([
            $trip->rt_price_php, $trip->ow_out_price_php, $trip->ow_back_price_php,
            $trip->rt_price_jpy, $trip->ow_out_price_jpy, $trip->ow_back_price_jpy,
        ])->contains(fn ($v) => $v !== null);

        if (! $hasAny) {
            return;
        }

        $owPhpSum = null;
        if ($trip->ow_out_price_php !== null || $trip->ow_back_price_php !== null) {
            $owPhpSum = (int) ($trip->ow_out_price_php ?? 0) + (int) ($trip->ow_back_price_php ?? 0);
        }
        $owJpySum = null;
        if ($trip->ow_out_price_jpy !== null || $trip->ow_back_price_jpy !== null) {
            $owJpySum = (int) ($trip->ow_out_price_jpy ?? 0) + (int) ($trip->ow_back_price_jpy ?? 0);
        }

        $comparePhp = $this->compareFares(
            $trip->rt_price_php !== null ? (int) $trip->rt_price_php : null,
            $owPhpSum
        );
        $compareJpy = $this->compareFares(
            $trip->rt_price_jpy !== null ? (int) $trip->rt_price_jpy : null,
            $owJpySum
        );

        $bestJpy = $this->bestJpyFare($trip);
        $profile = $this->getOrCreateProfile($userId);
        $underBudget = $bestJpy === null ? null : $bestJpy <= (int) $profile->budget_max_jpy;

        TravelFareSnapshot::query()->create([
            'user_id' => $userId,
            'travel_trip_id' => $trip->id,
            'rt_price_php' => $trip->rt_price_php,
            'ow_out_price_php' => $trip->ow_out_price_php,
            'ow_back_price_php' => $trip->ow_back_price_php,
            'rt_price_jpy' => $trip->rt_price_jpy,
            'ow_out_price_jpy' => $trip->ow_out_price_jpy,
            'ow_back_price_jpy' => $trip->ow_back_price_jpy,
            'winner_php' => $comparePhp['winner'],
            'winner_jpy' => $compareJpy['winner'],
            'under_budget_jpy' => $underBudget,
            'captured_at' => now(),
        ]);
    }

    public function maybeCreateBudgetAlert(int $userId, TravelTrip $trip): void
    {
        $bestJpy = $this->bestJpyFare($trip);
        if ($bestJpy === null) {
            return;
        }

        $profile = $this->getOrCreateProfile($userId);
        $budget = (int) $profile->budget_max_jpy;
        if ($bestJpy <= $budget) {
            return;
        }

        $label = (string) ($trip->label ?: __(self::PURPOSES[$trip->purpose] ?? $trip->purpose));
        $this->upsertAlert(
            $userId,
            'budget',
            'warn',
            __('予算超過: :label', ['label' => $label]),
            __('最安JPY目安 ¥:fare が予算上限 ¥:budget を超えています。', [
                'fare' => number_format($bestJpy),
                'budget' => number_format($budget),
            ]),
            'budget:trip:'.$trip->id.':'.$bestJpy
        );
    }

    private function bestJpyFare(TravelTrip $trip): ?int
    {
        $candidates = [];
        if ($trip->rt_price_jpy !== null) {
            $candidates[] = (int) $trip->rt_price_jpy;
        }
        if ($trip->ow_out_price_jpy !== null || $trip->ow_back_price_jpy !== null) {
            $candidates[] = (int) ($trip->ow_out_price_jpy ?? 0) + (int) ($trip->ow_back_price_jpy ?? 0);
        }

        return $candidates === [] ? null : min($candidates);
    }

    private function checkExpiringPromos(int $userId): int
    {
        $this->expireStalePromos($userId);
        $created = 0;
        $tz = config('app.timezone', 'Asia/Tokyo');
        $today = Carbon::now($tz)->startOfDay();

        TravelPromo::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['watching', 'usable'])
            ->whereNotNull('valid_until')
            ->each(function (TravelPromo $promo) use ($today, $userId, &$created) {
                $until = $promo->valid_until->copy()->startOfDay();
                $days = (int) $today->diffInDays($until, false);
                if ($days < 0 || $days > 7) {
                    return;
                }
                $created += $this->upsertAlert(
                    $userId,
                    'promo',
                    $days <= 2 ? 'danger' : 'warn',
                    __('プロモ期限間近: :code', ['code' => $promo->code]),
                    __('有効期限 :date（あと :n 日）', [
                        'date' => $until->format('Y-m-d'),
                        'n' => $days,
                    ]),
                    'promo:exp:'.$promo->id.':'.$until->format('Y-m-d')
                );
            });

        return $created;
    }

    private function expireStalePromos(int $userId): void
    {
        $tz = config('app.timezone', 'Asia/Tokyo');
        $today = Carbon::now($tz)->toDateString();

        TravelPromo::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['watching', 'usable'])
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', $today)
            ->update(['status' => 'expired']);
    }

    /**
     * @return int 新規作成なら 1、既存更新なら 0
     */
    public function upsertAlert(
        int $userId,
        string $type,
        string $severity,
        string $title,
        ?string $body,
        string $dedupeKey
    ): int {
        $existing = TravelAlert::query()
            ->where('user_id', $userId)
            ->where('dedupe_key', $dedupeKey)
            ->first();

        if ($existing) {
            $existing->fill([
                'type' => $type,
                'severity' => $severity,
                'title' => $title,
                'body' => $body,
            ]);
            $existing->save();

            return 0;
        }

        TravelAlert::query()->create([
            'user_id' => $userId,
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'body' => $body,
            'dedupe_key' => $dedupeKey,
        ]);

        return 1;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePromoPayload(array $payload, ?TravelPromo $existing = null): array
    {
        $code = trim((string) ($payload['code'] ?? $existing?->code ?? ''));
        if ($code === '') {
            throw new \InvalidArgumentException(__('プロモコードは必須です。'));
        }

        $status = (string) ($payload['status'] ?? $existing?->status ?? 'watching');
        if (! array_key_exists($status, self::PROMO_STATUSES)) {
            $status = 'watching';
        }
        $applies = (string) ($payload['appliesTo'] ?? $existing?->applies_to ?? 'both');
        if (! array_key_exists($applies, self::PROMO_APPLIES)) {
            $applies = 'both';
        }

        $validFrom = trim((string) ($payload['validFrom'] ?? ''));
        if ($validFrom === '' && $existing?->valid_from) {
            $validFrom = $existing->valid_from->format('Y-m-d');
        }
        $validUntil = trim((string) ($payload['validUntil'] ?? ''));
        if ($validUntil === '' && $existing?->valid_until) {
            $validUntil = $existing->valid_until->format('Y-m-d');
        }

        $url = trim((string) ($payload['sourceUrl'] ?? $existing?->source_url ?? ''));
        if ($url !== '' && ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException(__('ソースURLの形式が正しくありません。'));
        }

        return [
            'code' => mb_substr($code, 0, 64),
            'title' => trim((string) ($payload['title'] ?? $existing?->title ?? '')) ?: null,
            'source_url' => $url !== '' ? mb_substr($url, 0, 500) : null,
            'applies_to' => $applies,
            'status' => $status,
            'valid_from' => $validFrom !== '' ? $validFrom : null,
            'valid_until' => $validUntil !== '' ? $validUntil : null,
            'notes' => trim((string) ($payload['notes'] ?? $existing?->notes ?? '')) ?: null,
        ];
    }
}
