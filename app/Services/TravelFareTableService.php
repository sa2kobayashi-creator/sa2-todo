<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TravelFareTableService
{
    private const MAX_RANGE_DAYS = 62;

    public function __construct(private TravelpayoutsConfigService $travelpayouts) {}

    /**
     * @return array{
     *   mode: string,
     *   origin: string,
     *   destination: string,
     *   airlineCode: string,
     *   departFrom: string,
     *   departTo: string,
     *   returnFrom: string|null,
     *   returnTo: string|null,
     *   currency: string,
     *   rows: list<array<string, mixed>>,
     *   matrix: array<string, mixed>|null,
     *   cheapest: list<array<string, mixed>>,
     *   warnings: list<string>,
     *   notes: list<string>,
     *   fetchedAt: string
     * }
     */
    public function build(
        string $mode,
        string $departFrom,
        string $departTo,
        ?string $returnFrom = null,
        ?string $returnTo = null,
        string $origin = 'FUK',
        string $destination = 'MNL',
        string $airlineCode = '5J',
        string $currency = 'PHP',
    ): array {
        if ($this->travelpayouts->token() === '') {
            throw new \RuntimeException(
                __('Travelpayouts API トークンが未設定です。設定 → API設定 でトークンを登録してください。')
            );
        }

        $mode = $mode === 'rt' ? 'rt' : 'ow';
        $departFrom = $this->parseDate($departFrom, __('出発期間の開始日'));
        $departTo = $this->parseDate($departTo, __('出発期間の終了日'));
        if ($departFrom > $departTo) {
            throw new \InvalidArgumentException(__('出発期間の開始日は終了日以前にしてください。'));
        }
        $this->assertRangeDays($departFrom, $departTo, __('出発期間'));

        $returnFromDate = null;
        $returnToDate = null;
        if ($mode === 'rt') {
            $returnFromDate = $this->parseDate((string) $returnFrom, __('帰国期間の開始日'));
            $returnToDate = $this->parseDate((string) $returnTo, __('帰国期間の終了日'));
            if ($returnFromDate > $returnToDate) {
                throw new \InvalidArgumentException(__('帰国期間の開始日は終了日以前にしてください。'));
            }
            $this->assertRangeDays($returnFromDate, $returnToDate, __('帰国期間'));
        }

        $origin = strtoupper(trim($origin)) ?: 'FUK';
        $destination = strtoupper(trim($destination)) ?: 'MNL';
        $preferAirline = strtoupper(trim($airlineCode !== '' ? $airlineCode : $this->travelpayouts->preferAirline())) ?: '5J';
        $currency = strtoupper($currency) === 'JPY' ? 'JPY' : 'PHP';

        $warnings = [];
        $notes = [
            __('Travelpayouts の月次キャッシュから期間内の目安運賃を集計しています。'),
            __('日によってデータがない日は「—」になります。予約前に公式で再確認してください。'),
        ];

        if ($mode === 'ow') {
            $outbound = $this->legPricesByDate($origin, $destination, $departFrom, $departTo, $preferAirline, $warnings);
            $rows = $this->buildOneWayRows($departFrom, $departTo, $outbound, $currency);
            $cheapest = $this->pickCheapestRows($rows, $currency);

            if (! array_filter($rows, fn (array $row) => ! empty($row['hasData']))) {
                $warnings[] = __('この期間の片道キャッシュは見つかりませんでした。表は表示していますが、金額が入っている日がありません。');
            }

            $filledCount = count(array_filter($rows, fn (array $row) => ! empty($row['isFilled'])));
            if ($filledCount > 0) {
                $warnings[] = __('データがない日は、近い日のキャッシュから補完して表示しています（近似）。');
            }

            return [
                'mode' => 'ow',
                'origin' => $origin,
                'destination' => $destination,
                'airlineCode' => $preferAirline,
                'departFrom' => $departFrom,
                'departTo' => $departTo,
                'returnFrom' => null,
                'returnTo' => null,
                'currency' => $currency,
                'rows' => $rows,
                'matrix' => null,
                'cheapest' => $cheapest,
                'warnings' => array_values(array_unique($warnings)),
                'notes' => $notes,
                'fetchedAt' => now()->format('Y-m-d H:i'),
            ];
        }

        $outbound = $this->legPricesByDate($origin, $destination, $departFrom, $departTo, $preferAirline, $warnings);
        $inbound = $this->legPricesByDate($destination, $origin, $returnFromDate, $returnToDate, $preferAirline, $warnings);
        $rtPairs = $this->roundTripCalendarPairs(
            $origin,
            $destination,
            $departFrom,
            $departTo,
            $returnFromDate,
            $returnToDate,
            $currency,
            $preferAirline,
            $warnings
        );

        $matrix = $this->buildRoundTripMatrix(
            $departFrom,
            $departTo,
            $returnFromDate,
            $returnToDate,
            $outbound,
            $inbound,
            $rtPairs,
            $currency
        );

        if (($matrix['cells'] ?? []) === []) {
            $warnings[] = __('この期間の往復キャッシュは見つかりませんでした。表は表示していますが、利用可能な組み合わせがありません。');
            $warnings[] = __('Travelpayouts の往復キャッシュは疎なため、特定の往復日だけ表示されることがあります。');
        }

        return [
            'mode' => 'rt',
            'origin' => $origin,
            'destination' => $destination,
            'airlineCode' => $preferAirline,
            'departFrom' => $departFrom,
            'departTo' => $departTo,
            'returnFrom' => $returnFromDate,
            'returnTo' => $returnToDate,
            'currency' => $currency,
            'rows' => [],
            'matrix' => $matrix,
            'cheapest' => $matrix['cheapest'] ?? [],
            'warnings' => array_values(array_unique($warnings)),
            'notes' => $notes,
            'fetchedAt' => now()->format('Y-m-d H:i'),
        ];
    }

    private function parseDate(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException(__(':label を入力してください。', ['label' => $label]));
        }
        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            throw new \InvalidArgumentException(__(':label の形式が正しくありません。', ['label' => $label]));
        }
    }

    private function assertRangeDays(string $from, string $to, string $label): void
    {
        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        if ($days > self::MAX_RANGE_DAYS) {
            throw new \InvalidArgumentException(__(':label は最大 :max 日まで指定できます。', [
                'label' => $label,
                'max' => self::MAX_RANGE_DAYS,
            ]));
        }
    }

    /**
     * @param  list<string>  $warnings
     * @return array<string, array{pricePhp: int|null, priceJpy: int|null, airline: string, source: string}>
     */
    private function legPricesByDate(
        string $origin,
        string $destination,
        string $from,
        string $to,
        string $preferAirline,
        array &$warnings
    ): array {
        $byDate = [];
        foreach ($this->monthsInRange($from, $to) as $month) {
            foreach (['PHP', 'JPY'] as $currency) {
                // prices_for_dates（月指定）を優先。month-matrix は月ズレすることがある。
                $rows = $this->requestPricesForDatesMonth($origin, $destination, $month, $currency, true);
                if ($rows === []) {
                    $rows = $this->requestMonthMatrixMonth($origin, $destination, $month, $currency, false);
                }
                foreach ($rows as $row) {
                    $date = $row['departure_at'];
                    if ($date < $from || $date > $to) {
                        continue;
                    }
                    if (! isset($byDate[$date])) {
                        $byDate[$date] = [
                            'pricePhp' => null,
                            'priceJpy' => null,
                            'airline' => '',
                            'source' => (string) ($row['source'] ?? 'prices_for_dates'),
                        ];
                    }
                    $key = $currency === 'JPY' ? 'priceJpy' : 'pricePhp';
                    $price = (int) $row['price'];
                    $current = $byDate[$date][$key];
                    $airline = strtoupper((string) ($row['airline'] ?? ''));
                    if ($current === null || $this->isBetterPrice($current, $price, $byDate[$date]['airline'], $airline, $preferAirline)) {
                        $byDate[$date][$key] = $price;
                        if ($airline !== '') {
                            $byDate[$date]['airline'] = $airline;
                        }
                        $byDate[$date]['source'] = (string) ($row['source'] ?? 'prices_for_dates');
                    }
                }
            }
        }

        if ($byDate === []) {
            $warnings[] = __(
                ':route の :from〜:to にキャッシュ運賃がありませんでした。',
                ['route' => $origin.'→'.$destination, 'from' => $from, 'to' => $to]
            );
        }

        ksort($byDate);

        return $byDate;
    }

    private function isBetterPrice(?int $current, int $candidate, string $currentAirline, string $candidateAirline, string $preferAirline): bool
    {
        if ($current === null) {
            return true;
        }
        $currentPreferred = $currentAirline === $preferAirline;
        $candidatePreferred = $candidateAirline === $preferAirline;
        if ($candidatePreferred && ! $currentPreferred) {
            return true;
        }
        if ($currentPreferred && ! $candidatePreferred) {
            return false;
        }

        return $candidate < $current;
    }

    /**
     * @param  array<string, array{pricePhp: int|null, priceJpy: int|null, airline: string, source: string}>  $outbound
     * @return list<array<string, mixed>>
     */
    private function buildOneWayRows(string $from, string $to, array $outbound, string $currency): array
    {
        $rows = [];
        $minPrice = null; // exact-cache only

        $priceKey = $currency === 'JPY' ? 'priceJpy' : 'pricePhp';
        $availableDates = [];
        foreach ($outbound as $date => $leg) {
            if (! is_array($leg)) {
                continue;
            }
            if (! empty($leg[$priceKey]) && (int) $leg[$priceKey] > 0) {
                $availableDates[] = (string) $date;
            }
        }

        $maxFillDays = 10; // keep it "nearby", not a long extrapolation

        foreach ($this->dateRange($from, $to) as $date) {
            $leg = $outbound[$date] ?? null;

            $pricePhp = $leg['pricePhp'] ?? null;
            $priceJpy = $leg['priceJpy'] ?? null;
            $airline = $leg['airline'] ?? '';
            $source = $leg['source'] ?? '';
            $hasData = $leg !== null;
            $isFilled = false;

            $exactPrice = $leg[$priceKey] ?? null;
            if ($exactPrice === null && $availableDates !== []) {
                $target = Carbon::parse($date)->startOfDay();
                $bestDate = null;
                $bestDiff = PHP_INT_MAX;
                foreach ($availableDates as $cand) {
                    try {
                        $diff = (int) abs(Carbon::parse($cand)->startOfDay()->diffInDays($target));
                    } catch (\Throwable) {
                        continue;
                    }
                    if ($diff < $bestDiff) {
                        $bestDiff = $diff;
                        $bestDate = $cand;
                    }
                }

                if ($bestDate !== null && $bestDiff <= $maxFillDays) {
                    $nearLeg = $outbound[$bestDate] ?? null;
                    if (is_array($nearLeg)) {
                        $pricePhp = $nearLeg['pricePhp'] ?? $pricePhp;
                        $priceJpy = $nearLeg['priceJpy'] ?? $priceJpy;
                        $airline = (string) ($nearLeg['airline'] ?? $airline);
                        $source = (string) ($nearLeg['source'] ?? $source);
                        $hasData = false;
                        $isFilled = true;
                    }
                }
            }

            $display = $currency === 'JPY' ? $priceJpy : $pricePhp;
            if ($hasData && $display !== null && ($minPrice === null || $display < $minPrice)) {
                $minPrice = $display;
            }

            $rows[] = [
                'departOn' => $date,
                'pricePhp' => $pricePhp,
                'priceJpy' => $priceJpy,
                'airline' => $airline,
                'source' => $source,
                'hasData' => $hasData,
                'isFilled' => $isFilled,
            ];
        }

        foreach ($rows as &$row) {
            $display = $currency === 'JPY' ? $row['priceJpy'] : $row['pricePhp'];
            $row['isCheapest'] = $row['hasData'] && $display !== null && $minPrice !== null && $display === $minPrice;
        }
        unset($row);

        usort($rows, fn (array $a, array $b) => strcmp($a['departOn'], $b['departOn']));

        return $rows;
    }

    /**
     * @param  array<string, array{pricePhp: int|null, priceJpy: int|null, airline: string, source: string}>  $outbound
     * @param  array<string, array{pricePhp: int|null, priceJpy: int|null, airline: string, source: string}>  $inbound
     * @param  array<string, array{pricePhp: int|null, priceJpy: int|null, airline: string, source: string}>  $rtPairs
     * @return array{departDates: list<string>, returnDates: list<string>, cells: list<array<string, mixed>>, cheapest: list<array<string, mixed>>}
     */
    private function buildRoundTripMatrix(
        string $departFrom,
        string $departTo,
        string $returnFrom,
        string $returnTo,
        array $outbound,
        array $inbound,
        array $rtPairs,
        string $currency
    ): array {
        $departDates = $this->dateRange($departFrom, $departTo);
        $returnDates = $this->dateRange($returnFrom, $returnTo);
        $cells = [];
        $flat = [];

        foreach ($departDates as $departOn) {
            foreach ($returnDates as $returnOn) {
                if ($returnOn < $departOn) {
                    continue;
                }

                $out = $outbound[$departOn] ?? null;
                $back = $inbound[$returnOn] ?? null;
                $pairKey = $departOn.'|'.$returnOn;
                $rt = $rtPairs[$pairKey] ?? null;

                $owSumPhp = ($out && $out['pricePhp'] !== null && $back && $back['pricePhp'] !== null)
                    ? $out['pricePhp'] + $back['pricePhp']
                    : null;
                $owSumJpy = ($out && $out['priceJpy'] !== null && $back && $back['priceJpy'] !== null)
                    ? $out['priceJpy'] + $back['priceJpy']
                    : null;

                $totalPhp = $rt['pricePhp'] ?? $owSumPhp;
                $totalJpy = $rt['priceJpy'] ?? $owSumJpy;
                $hasData = $totalPhp !== null || $totalJpy !== null;
                if (! $hasData) {
                    continue;
                }

                $cell = [
                    'departOn' => $departOn,
                    'returnOn' => $returnOn,
                    'pricePhp' => $totalPhp,
                    'priceJpy' => $totalJpy,
                    'owOutPhp' => $out['pricePhp'] ?? null,
                    'owBackPhp' => $back['pricePhp'] ?? null,
                    'owOutJpy' => $out['priceJpy'] ?? null,
                    'owBackJpy' => $back['priceJpy'] ?? null,
                    'rtPhp' => $rt['pricePhp'] ?? null,
                    'rtJpy' => $rt['priceJpy'] ?? null,
                    'source' => $rt ? 'calendar' : 'ow-sum',
                    'hasData' => true,
                    'isCheapest' => false,
                ];
                $cells[] = $cell;
                $flat[] = $cell;
            }
        }

        $cheapest = $this->pickCheapestRtCells($flat, $currency);
        $cheapestKeys = [];
        foreach ($cheapest as $item) {
            $cheapestKeys[$item['departOn'].'|'.$item['returnOn']] = true;
        }
        foreach ($cells as &$cell) {
            $cell['isCheapest'] = isset($cheapestKeys[$cell['departOn'].'|'.$cell['returnOn']]);
        }
        unset($cell);

        return [
            'departDates' => $departDates,
            'returnDates' => $returnDates,
            'cells' => $cells,
            'cheapest' => $cheapest,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function pickCheapestRows(array $rows, string $currency, int $limit = 10): array
    {
        $priced = array_values(array_filter($rows, function (array $row) use ($currency) {
            $price = $currency === 'JPY' ? $row['priceJpy'] : $row['pricePhp'];

            return $price !== null;
        }));
        usort($priced, function (array $a, array $b) use ($currency) {
            $pa = $currency === 'JPY' ? $a['priceJpy'] : $a['pricePhp'];
            $pb = $currency === 'JPY' ? $b['priceJpy'] : $b['pricePhp'];
            if ($pa === $pb) {
                return strcmp((string) $a['departOn'], (string) $b['departOn']);
            }

            return $pa <=> $pb;
        });

        return array_slice($priced, 0, $limit);
    }

    /**
     * @param  list<array<string, mixed>>  $cells
     * @return list<array<string, mixed>>
     */
    private function pickCheapestRtCells(array $cells, string $currency, int $limit = 10): array
    {
        $priced = array_values(array_filter($cells, function (array $cell) use ($currency) {
            $price = $currency === 'JPY' ? $cell['priceJpy'] : $cell['pricePhp'];

            return $price !== null;
        }));
        usort($priced, function (array $a, array $b) use ($currency) {
            $pa = $currency === 'JPY' ? $a['priceJpy'] : $a['pricePhp'];
            $pb = $currency === 'JPY' ? $b['priceJpy'] : $b['pricePhp'];
            if ($pa === $pb) {
                return strcmp((string) $a['departOn'], (string) $b['departOn']);
            }

            return $pa <=> $pb;
        });

        return array_slice($priced, 0, $limit);
    }

    /**
     * @param  list<string>  $warnings
     * @return array<string, array{pricePhp: int|null, priceJpy: int|null, airline: string, source: string}>
     */
    private function roundTripCalendarPairs(
        string $origin,
        string $destination,
        string $departFrom,
        string $departTo,
        string $returnFrom,
        string $returnTo,
        string $currency,
        string $preferAirline,
        array &$warnings
    ): array {
        $pairs = [];
        foreach ($this->monthsInRange($departFrom, $departTo) as $month) {
            foreach (['PHP', 'JPY'] as $curr) {
                $rows = $this->requestCalendarMonth($origin, $destination, $month, $curr);
                foreach ($rows as $row) {
                    $departOn = substr((string) ($row['departure_at'] ?? ''), 0, 10);
                    $returnOn = substr((string) ($row['return_at'] ?? ''), 0, 10);
                    if ($departOn === '' || $returnOn === '') {
                        continue;
                    }
                    if ($departOn < $departFrom || $departOn > $departTo) {
                        continue;
                    }
                    if ($returnOn < $returnFrom || $returnOn > $returnTo) {
                        continue;
                    }
                    if ($returnOn < $departOn) {
                        continue;
                    }

                    $key = $departOn.'|'.$returnOn;
                    if (! isset($pairs[$key])) {
                        $pairs[$key] = [
                            'pricePhp' => null,
                            'priceJpy' => null,
                            'airline' => '',
                            'source' => 'calendar',
                        ];
                    }
                    $price = (int) $row['price'];
                    $airline = strtoupper((string) ($row['airline'] ?? ''));
                    $priceKey = $curr === 'JPY' ? 'priceJpy' : 'pricePhp';
                    $current = $pairs[$key][$priceKey];
                    if ($current === null || $this->isBetterPrice($current, $price, $pairs[$key]['airline'], $airline, $preferAirline)) {
                        $pairs[$key][$priceKey] = $price;
                        if ($airline !== '') {
                            $pairs[$key]['airline'] = $airline;
                        }
                    }
                }
            }
        }

        if ($pairs !== []) {
            $warnings[] = __('往復一括のキャッシュがある組み合わせは calendar 価格を優先しています。');
        }

        return $pairs;
    }

    /** @return list<string> */
    private function monthsInRange(string $from, string $to): array
    {
        $months = [];
        $cursor = Carbon::parse($from)->startOfMonth();
        $end = Carbon::parse($to)->startOfMonth();
        while ($cursor <= $end) {
            $months[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

        return array_values(array_unique($months));
    }

    /** @return list<string> */
    private function dateRange(string $from, string $to): array
    {
        $dates = [];
        $cursor = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        while ($cursor <= $end) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor->addDay();
        }

        return $dates;
    }

    /**
     * @return list<array{price: int, departure_at: string, airline: string, source: string}>
     */
    private function requestPricesForDatesMonth(
        string $origin,
        string $destination,
        string $month,
        string $currency,
        bool $oneWay
    ): array {
        $token = $this->travelpayouts->token();
        $base = $this->travelpayouts->baseUrl();
        $currency = strtoupper($currency);
        $market = $currency === 'JPY'
            ? $this->travelpayouts->marketJpy()
            : $this->travelpayouts->marketPhp();

        try {
            $attempts = $this->travelpayouts->directOnly() ? [true, false] : [false];
            $byDate = [];

            foreach ($attempts as $direct) {
                $response = Http::timeout(25)
                    ->withHeaders([
                        'X-Access-Token' => $token,
                        'Accept' => 'application/json',
                    ])
                    ->get($base.'/aviasales/v3/prices_for_dates', [
                        'origin' => $origin,
                        'destination' => $destination,
                        'departure_at' => $month,
                        'one_way' => $oneWay ? 'true' : 'false',
                        'direct' => $direct ? 'true' : 'false',
                        'sorting' => 'price',
                        'unique' => 'false',
                        'cy' => strtolower($currency),
                        'currency' => strtolower($currency),
                        'market' => $market,
                        'limit' => 100,
                        'page' => 1,
                        'token' => $token,
                    ]);

                if (! $response->successful()) {
                    continue;
                }

                $json = $response->json();
                if (! is_array($json) || empty($json['success'])) {
                    continue;
                }

                $data = $json['data'] ?? [];
                if (! is_array($data) || $data === []) {
                    continue;
                }

                foreach ($data as $row) {
                    if (! is_array($row) || ! isset($row['price']) || (int) $row['price'] <= 0) {
                        continue;
                    }
                    $dep = substr((string) ($row['departure_at'] ?? ''), 0, 10);
                    if ($dep === '' || ! str_starts_with($dep, $month)) {
                        continue;
                    }
                    $price = (int) $row['price'];
                    $airline = strtoupper((string) ($row['airline'] ?? ''));
                    if (! isset($byDate[$dep]) || $price < $byDate[$dep]['price']) {
                        $byDate[$dep] = [
                            'price' => $price,
                            'departure_at' => $dep,
                            'airline' => $airline,
                            'source' => 'prices_for_dates',
                        ];
                    }
                }

                if ($byDate !== []) {
                    break;
                }
            }

            return array_values($byDate);
        } catch (\Throwable $e) {
            Log::warning('travel.fare_table_prices_for_dates_failed', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return list<array{price: int, departure_at: string, airline: string, source: string}>
     */
    private function requestMonthMatrixMonth(
        string $origin,
        string $destination,
        string $month,
        string $currency,
        bool $sameMonthOnly = true
    ): array {
        $token = $this->travelpayouts->token();
        $base = $this->travelpayouts->baseUrl();

        try {
            $response = Http::timeout(25)
                ->withHeaders([
                    'X-Access-Token' => $token,
                    'Accept' => 'application/json',
                ])
                ->get($base.'/v2/prices/month-matrix', [
                    'origin' => $origin,
                    'destination' => $destination,
                    'month' => $month,
                    'currency' => strtolower($currency),
                    'show_to_affiliates' => 'true',
                    'token' => $token,
                ]);

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json('data') ?? [];
            if (! is_array($data)) {
                return [];
            }

            $rows = [];
            foreach ($data as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $price = (int) ($row['value'] ?? $row['price'] ?? 0);
                $dep = (string) ($row['depart_date'] ?? '');
                if ($price <= 0 || $dep === '') {
                    continue;
                }
                if ($sameMonthOnly && ! str_starts_with($dep, $month)) {
                    continue;
                }
                if ($this->travelpayouts->directOnly() && isset($row['number_of_changes']) && (int) $row['number_of_changes'] > 0) {
                    continue;
                }
                $rows[] = [
                    'price' => $price,
                    'departure_at' => $dep,
                    'airline' => (string) ($row['airline'] ?? ''),
                    'source' => 'month-matrix',
                ];
            }

            return $rows;
        } catch (\Throwable $e) {
            Log::warning('travel.fare_table_matrix_failed', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return list<array{price: int, departure_at: string, return_at: string, airline: string}>
     */
    private function requestCalendarMonth(
        string $origin,
        string $destination,
        string $month,
        string $currency
    ): array {
        $token = $this->travelpayouts->token();
        $base = $this->travelpayouts->baseUrl();

        try {
            $response = Http::timeout(25)
                ->withHeaders([
                    'X-Access-Token' => $token,
                    'Accept' => 'application/json',
                ])
                ->get($base.'/v1/prices/calendar', [
                    'origin' => $origin,
                    'destination' => $destination,
                    'depart_date' => $month,
                    'currency' => strtolower($currency),
                    'token' => $token,
                ]);

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json('data') ?? [];
            if (! is_array($data)) {
                return [];
            }

            $rows = [];
            foreach ($data as $row) {
                if (! is_array($row) || ! isset($row['price']) || (int) $row['price'] <= 0) {
                    continue;
                }
                if (empty($row['return_at'])) {
                    continue;
                }
                $rows[] = [
                    'price' => (int) $row['price'],
                    'departure_at' => (string) ($row['departure_at'] ?? ''),
                    'return_at' => (string) ($row['return_at'] ?? ''),
                    'airline' => (string) ($row['airline'] ?? ''),
                ];
            }

            return $rows;
        } catch (\Throwable $e) {
            Log::warning('travel.fare_table_calendar_failed', ['message' => $e->getMessage()]);

            return [];
        }
    }
}
