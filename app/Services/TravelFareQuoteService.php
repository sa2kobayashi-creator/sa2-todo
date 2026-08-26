<?php

namespace App\Services;

use App\Support\AirlineName;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Travelpayouts (Aviasales) Data API で運賃目安を取得する。
 * キャッシュ価格のため公式と差が出ることがある。予約前は公式で再確認すること。
 */
class TravelFareQuoteService
{
    public function __construct(private TravelpayoutsConfigService $travelpayouts) {}

    /**
     * @return array{
     *   origin: string,
     *   destination: string,
     *   airlineCode: string,
     *   departOn: string,
     *   returnOn: string|null,
     *   bookedAs: string,
     *   rtPricePhp: int|null,
     *   owOutPricePhp: int|null,
     *   owBackPricePhp: int|null,
     *   rtPriceJpy: int|null,
     *   owOutPriceJpy: int|null,
     *   owBackPriceJpy: int|null,
     *   owSumPhp: int|null,
     *   owSumJpy: int|null,
     *   rtFromOwFallback: bool,
     *   comparePhp: array{winner: string|null, delta: int|null, label: string},
     *   compareJpy: array{winner: string|null, delta: int|null, label: string},
     *   source: string,
     *   sourceUrl: string,
     *   fetchedAt: string,
     *   notes: list<string>,
     *   warnings: list<string>
     * }
     */
    public function quote(
        string $departOn,
        ?string $returnOn = null,
        string $origin = '',
        string $destination = '',
        string $airlineCode = '',
        string $bookedAs = 'rt'
    ): array {
        $token = $this->travelpayouts->token();
        if ($token === '') {
            throw new \RuntimeException(
                __('Travelpayouts API トークンが未設定です。設定 → API設定 でトークンを登録してください。')
            );
        }

        $departOn = trim($departOn);
        $returnOn = $returnOn !== null ? trim($returnOn) : '';
        if ($departOn === '') {
            throw new \InvalidArgumentException(__('出発日は必須です。'));
        }
        try {
            Carbon::parse($departOn);
            if ($returnOn !== '') {
                Carbon::parse($returnOn);
            }
        } catch (\Throwable) {
            throw new \InvalidArgumentException(__('日付の形式が正しくありません。'));
        }

        $origin = strtoupper(trim($origin));
        $destination = strtoupper(trim($destination));
        if ($origin === '' || $destination === '') {
            throw new \InvalidArgumentException(__('出発空港と到着空港を入力してください。'));
        }
        $preferAirline = strtoupper(trim($airlineCode !== '' ? $airlineCode : $this->travelpayouts->preferAirline()));

        $warnings = [];
        $notes = [
            __('Travelpayouts API のキャッシュ価格（税込目安）。座席・条件により変動します。'),
            __('予約前に航空会社または比較サイトで税込金額を再確認してください。'),
        ];

        $owOutPhp = $this->fetchCheapest($origin, $destination, $departOn, null, 'PHP', $preferAirline, true, $warnings);
        $owOutJpy = $this->fetchCheapest($origin, $destination, $departOn, null, 'JPY', $preferAirline, true, $warnings);

        $owBackPhp = null;
        $owBackJpy = null;
        $rtPhp = null;
        $rtJpy = null;
        $rtFromOwFallback = false;
        $sourceUrl = $this->searchUrl($origin, $destination, $departOn, $returnOn !== '' ? $returnOn : null);

        if ($returnOn !== '') {
            $owBackPhp = $this->fetchCheapest($destination, $origin, $returnOn, null, 'PHP', $preferAirline, true, $warnings);
            $owBackJpy = $this->fetchCheapest($destination, $origin, $returnOn, null, 'JPY', $preferAirline, true, $warnings);

            $owSumPhpTmp = ($owOutPhp !== null && $owBackPhp !== null) ? ($owOutPhp + $owBackPhp) : null;
            $owSumJpyTmp = ($owOutJpy !== null && $owBackJpy !== null) ? ($owOutJpy + $owBackJpy) : null;

            $rtPhp = $this->sanitizeRoundTrip(
                $this->fetchCheapest($origin, $destination, $departOn, $returnOn, 'PHP', $preferAirline, false, $warnings),
                $owSumPhpTmp
            );
            $rtJpy = $this->sanitizeRoundTrip(
                $this->fetchCheapest($origin, $destination, $departOn, $returnOn, 'JPY', $preferAirline, false, $warnings),
                $owSumJpyTmp
            );

            if ($bookedAs === 'rt') {
                if ($rtPhp === null && $owSumPhpTmp !== null) {
                    $rtPhp = $owSumPhpTmp;
                    $rtFromOwFallback = true;
                }
                if ($rtJpy === null && $owSumJpyTmp !== null) {
                    $rtJpy = $owSumJpyTmp;
                    $rtFromOwFallback = true;
                }
                if ($rtFromOwFallback) {
                    $warnings[] = __('往復一括の見積もりは取れなかったため、片道合計を RT 欄に入れています（参考）。');
                }
            } elseif ($rtPhp === null && $owSumPhpTmp !== null) {
                $warnings[] = __('往復一括（RT）の信頼できる見積もりは取得できませんでした。片道合計を主に比較してください。');
            }
        }

        if ($owOutPhp === null && $owOutJpy === null && $rtPhp === null && $rtJpy === null) {
            throw new \RuntimeException(__('運賃を取得できませんでした。日付を変えて再試行するか、公式サイトで確認してください。'));
        }

        $owSumPhp = null;
        if ($owOutPhp !== null || $owBackPhp !== null) {
            if ($returnOn === '') {
                $owSumPhp = $owOutPhp;
            } elseif ($owOutPhp !== null && $owBackPhp !== null) {
                $owSumPhp = $owOutPhp + $owBackPhp;
            }
        }

        $owSumJpy = null;
        if ($owOutJpy !== null || $owBackJpy !== null) {
            if ($returnOn === '') {
                $owSumJpy = $owOutJpy;
            } elseif ($owOutJpy !== null && $owBackJpy !== null) {
                $owSumJpy = $owOutJpy + $owBackJpy;
            }
        }

        $travel = app(TravelService::class);
        $compareRtPhp = ($bookedAs === 'rt' && $rtFromOwFallback) ? null : $rtPhp;
        $compareRtJpy = ($bookedAs === 'rt' && $rtFromOwFallback) ? null : $rtJpy;
        $comparePhp = $travel->compareFares($compareRtPhp, $owSumPhp);
        $compareJpy = $travel->compareFares($compareRtJpy, $owSumJpy);

        app(IntegrationUsageService::class)->increment('travelpayouts');

        return [
            'origin' => $origin,
            'destination' => $destination,
            'airlineCode' => $preferAirline,
            'airlineLabel' => AirlineName::label($preferAirline),
            'departOn' => $departOn,
            'returnOn' => $returnOn !== '' ? $returnOn : null,
            'bookedAs' => $bookedAs,
            'rtPricePhp' => $rtPhp,
            'owOutPricePhp' => $owOutPhp,
            'owBackPricePhp' => $owBackPhp,
            'rtPriceJpy' => $rtJpy,
            'owOutPriceJpy' => $owOutJpy,
            'owBackPriceJpy' => $owBackJpy,
            'owSumPhp' => $owSumPhp,
            'owSumJpy' => $owSumJpy,
            'rtFromOwFallback' => $rtFromOwFallback,
            'comparePhp' => $comparePhp,
            'compareJpy' => $compareJpy,
            'source' => 'Travelpayouts',
            'sourceUrl' => $sourceUrl,
            'confirmUrls' => $this->confirmUrls($preferAirline, $origin, $destination, $departOn, $returnOn !== '' ? $returnOn : null),
            'fetchedAt' => now()->format('Y-m-d H:i'),
            'notes' => $notes,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param  list<string>  $warnings
     */
    private function fetchCheapest(
        string $origin,
        string $destination,
        string $departOn,
        ?string $returnOn,
        string $currency,
        string $preferAirline,
        bool $oneWay,
        array &$warnings
    ): ?int {
        $rows = $this->collectFareRows($origin, $destination, $departOn, $returnOn, $currency, $oneWay, $warnings);
        if ($rows === []) {
            return null;
        }

        if ($preferAirline === '') {
            return (int) min(array_map(fn (array $r) => (int) $r['price'], $rows));
        }

        $preferred = array_values(array_filter(
            $rows,
            fn (array $row) => strtoupper((string) ($row['airline'] ?? '')) === $preferAirline
        ));

        if ($preferred !== []) {
            return (int) min(array_map(fn (array $r) => (int) $r['price'], $preferred));
        }

        $withAirline = array_values(array_filter(
            $rows,
            fn (array $row) => trim((string) ($row['airline'] ?? '')) !== ''
        ));
        $pool = $withAirline !== [] ? $withAirline : $rows;

        if ($withAirline !== []) {
            $warnings[] = __(
                ':route で :airline の価格が見つからず、他社最安を採用しました。',
                [
                    'route' => $origin.'→'.$destination.' '.$departOn.($returnOn ? '/'.$returnOn : ''),
                    'airline' => $preferAirline,
                ]
            );
        }

        return (int) min(array_map(fn (array $r) => (int) $r['price'], $pool));
    }

    /**
     * @param  list<string>  $warnings
     * @return list<array{price: int, airline?: string, departure_at?: string, return_at?: string, source?: string}>
     */
    private function collectFareRows(
        string $origin,
        string $destination,
        string $departOn,
        ?string $returnOn,
        string $currency,
        bool $oneWay,
        array &$warnings
    ): array {
        $directPreferred = $this->travelpayouts->directOnly();

        // 1) 日付指定 prices_for_dates（直行優先 → 乗り継ぎ許可）
        foreach ([true, false] as $direct) {
            if ($directPreferred === false && $direct === true) {
                continue;
            }
            $rows = $this->requestPricesForDates($origin, $destination, $departOn, $returnOn, $currency, $oneWay, $direct);
            $matched = $this->filterByExactDates($rows, $departOn, $oneWay ? null : $returnOn);
            if ($matched !== []) {
                return $matched;
            }
            if ($rows !== []) {
                $this->warnApproxDate($warnings, $origin, $destination, $departOn);
                return $rows;
            }
            if ($directPreferred === false) {
                break;
            }
        }

        // 2) 月指定 prices_for_dates
        $month = substr($departOn, 0, 7);
        $returnMonth = ($returnOn && ! $oneWay) ? substr($returnOn, 0, 7) : null;
        $monthRows = $this->requestPricesForDates($origin, $destination, $month, $returnMonth, $currency, $oneWay, false);
        if ($monthRows !== []) {
            $closest = $this->pickClosestRows($monthRows, $departOn, $oneWay ? null : $returnOn);
            if ($closest !== []) {
                $this->warnApproxDate($warnings, $origin, $destination, $departOn, $closest[0]['departure_at'] ?? null);

                return $closest;
            }
        }

        // 3) 片道: month-matrix（FUK など prices_for_dates が薄い路線向け）
        if ($oneWay) {
            $matrix = $this->requestMonthMatrix($origin, $destination, $departOn, $currency, true);
            if ($matrix === []) {
                // 指定月が空なら隣接月も試す
                $matrix = $this->requestMonthMatrix($origin, $destination, $departOn, $currency, false);
            }
            if ($matrix !== []) {
                $closest = $this->pickClosestMatrixRows($matrix, $departOn);
                if ($closest !== []) {
                    $this->warnApproxDate($warnings, $origin, $destination, $departOn, $closest[0]['departure_at'] ?? null);

                    return $closest;
                }
            }
        }

        // 4) cheap / calendar 月次キャッシュ
        $cheap = $this->requestCheapOrCalendar($origin, $destination, $departOn, $returnOn, $currency, $oneWay);
        if ($cheap !== []) {
            $this->warnApproxDate($warnings, $origin, $destination, $departOn, $cheap[0]['departure_at'] ?? null);

            return $cheap;
        }

        Log::info('travel.travelpayouts_empty', [
            'origin' => $origin,
            'destination' => $destination,
            'departOn' => $departOn,
            'returnOn' => $returnOn,
            'currency' => $currency,
            'oneWay' => $oneWay,
        ]);

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function filterByExactDates(array $rows, string $departOn, ?string $returnOn): array
    {
        $matched = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['price']) || (int) $row['price'] <= 0) {
                continue;
            }
            $depAt = isset($row['departure_at']) ? substr((string) $row['departure_at'], 0, 10) : null;
            if ($depAt && $depAt !== $departOn) {
                continue;
            }
            if ($returnOn) {
                $retAt = isset($row['return_at']) ? substr((string) $row['return_at'], 0, 10) : null;
                if ($retAt && $retAt !== $returnOn) {
                    continue;
                }
            }
            $matched[] = $row;
        }

        return $matched;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function pickClosestRows(array $rows, string $departOn, ?string $returnOn): array
    {
        $target = Carbon::parse($departOn)->startOfDay();
        $best = null;
        $bestDiff = PHP_INT_MAX;

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['price']) || (int) $row['price'] <= 0) {
                continue;
            }
            $depAt = isset($row['departure_at']) ? substr((string) $row['departure_at'], 0, 10) : null;
            if (! $depAt) {
                continue;
            }
            try {
                $diff = abs(Carbon::parse($depAt)->startOfDay()->diffInDays($target));
            } catch (\Throwable) {
                continue;
            }
            if ($returnOn) {
                $retAt = isset($row['return_at']) ? substr((string) $row['return_at'], 0, 10) : null;
                if ($retAt) {
                    try {
                        $diff += abs(Carbon::parse($retAt)->startOfDay()->diffInDays(Carbon::parse($returnOn)->startOfDay()));
                    } catch (\Throwable) {
                        // ignore
                    }
                }
            }
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $row;
            }
        }

        return $best ? [$best] : [];
    }

    /**
     * @param  list<array{price: int, airline?: string, departure_at?: string}>  $rows
     * @return list<array{price: int, airline?: string, departure_at?: string}>
     */
    private function pickClosestMatrixRows(array $rows, string $departOn): array
    {
        $target = Carbon::parse($departOn)->startOfDay();
        $best = null;
        $bestDiff = PHP_INT_MAX;
        $bestPrice = PHP_INT_MAX;

        foreach ($rows as $row) {
            $depAt = isset($row['departure_at']) ? substr((string) $row['departure_at'], 0, 10) : null;
            if (! $depAt) {
                continue;
            }
            try {
                $diff = (int) abs(Carbon::parse($depAt)->startOfDay()->diffInDays($target));
            } catch (\Throwable) {
                continue;
            }
            $price = (int) $row['price'];
            if ($diff < $bestDiff || ($diff === $bestDiff && $price < $bestPrice)) {
                $bestDiff = $diff;
                $bestPrice = $price;
                $best = $row;
            }
        }

        // 指定日±14日を超える場合は月内最安を使う
        if ($best === null || $bestDiff > 14) {
            $cheapest = null;
            $min = PHP_INT_MAX;
            foreach ($rows as $row) {
                $price = (int) ($row['price'] ?? 0);
                if ($price > 0 && $price < $min) {
                    $min = $price;
                    $cheapest = $row;
                }
            }

            return $cheapest ? [$cheapest] : [];
        }

        return [$best];
    }

    /** @param  list<string>  $warnings */
    private function warnApproxDate(array &$warnings, string $origin, string $destination, string $requested, mixed $foundAt = null): void
    {
        $found = is_string($foundAt) ? substr($foundAt, 0, 10) : null;
        if ($found && $found !== $requested) {
            $warnings[] = __(
                ':route の指定日（:requested）に近いキャッシュ（:found）を使用しています。',
                [
                    'route' => $origin.'→'.$destination,
                    'requested' => $requested,
                    'found' => $found,
                ]
            );
        } else {
            $warnings[] = __(
                ':route は指定日の厳密一致がなく、月次キャッシュから取得しています。',
                ['route' => $origin.'→'.$destination.' '.$requested]
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requestPricesForDates(
        string $origin,
        string $destination,
        string $departOn,
        ?string $returnOn,
        string $currency,
        bool $oneWay,
        bool $direct
    ): array {
        $token = $this->travelpayouts->token();
        $base = $this->travelpayouts->baseUrl();
        $currency = strtoupper($currency);
        $market = $currency === 'JPY'
            ? $this->travelpayouts->marketJpy()
            : $this->travelpayouts->marketPhp();

        $params = [
            'origin' => $origin,
            'destination' => $destination,
            'departure_at' => $departOn,
            'one_way' => $oneWay ? 'true' : 'false',
            'direct' => $direct ? 'true' : 'false',
            'sorting' => 'price',
            'unique' => 'false',
            'cy' => strtolower($currency),
            'currency' => strtolower($currency),
            'market' => $market,
            'limit' => 30,
            'page' => 1,
            'token' => $token,
        ];
        if (! $oneWay && $returnOn) {
            $params['return_at'] = $returnOn;
        }

        try {
            $response = Http::timeout(25)
                ->withHeaders([
                    'X-Access-Token' => $token,
                    'Accept' => 'application/json',
                ])
                ->get($base.'/aviasales/v3/prices_for_dates', $params);

            if (! $response->successful()) {
                Log::warning('travel.travelpayouts_http', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 300),
                    'params' => array_diff_key($params, ['token' => true]),
                ]);

                return [];
            }

            $json = $response->json();
            if (! is_array($json) || empty($json['success'])) {
                Log::warning('travel.travelpayouts_unsuccessful', [
                    'json' => is_array($json) ? array_diff_key($json, ['data' => true]) : null,
                ]);

                return [];
            }

            $data = $json['data'] ?? [];
            if (! is_array($data)) {
                return [];
            }

            $rows = [];
            foreach ($data as $row) {
                if (! is_array($row) || ! isset($row['price']) || (int) $row['price'] <= 0) {
                    continue;
                }
                $rows[] = $row;
            }

            return $rows;
        } catch (\Throwable $e) {
            Log::warning('travel.travelpayouts_failed', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return list<array{price: int, airline?: string, departure_at: string, source: string}>
     */
    private function requestMonthMatrix(
        string $origin,
        string $destination,
        string $departOn,
        string $currency,
        bool $sameMonthOnly = true
    ): array {
        $token = $this->travelpayouts->token();
        $base = $this->travelpayouts->baseUrl();
        $month = substr($departOn, 0, 7);

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

            $json = $response->json();
            $data = is_array($json) ? ($json['data'] ?? []) : [];
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
            Log::warning('travel.travelpayouts_matrix_failed', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return list<array{price: int, airline?: string, departure_at?: string, return_at?: string, source: string}>
     */
    private function requestCheapOrCalendar(
        string $origin,
        string $destination,
        string $departOn,
        ?string $returnOn,
        string $currency,
        bool $oneWay
    ): array {
        $token = $this->travelpayouts->token();
        $base = $this->travelpayouts->baseUrl();
        $month = substr($departOn, 0, 7);

        $params = [
            'origin' => $origin,
            'destination' => $destination,
            'depart_date' => $month,
            'currency' => strtolower($currency),
            'token' => $token,
        ];
        if (! $oneWay && $returnOn) {
            $params['return_date'] = substr($returnOn, 0, 7);
        }

        try {
            $response = Http::timeout(25)
                ->withHeaders([
                    'X-Access-Token' => $token,
                    'Accept' => 'application/json',
                ])
                ->get($base.'/v1/prices/cheap', $params);

            if ($response->successful()) {
                $json = $response->json();
                $data = is_array($json) ? ($json['data'] ?? []) : [];
                if (is_array($data) && $data !== []) {
                    $rows = [];
                    foreach ($data as $destRows) {
                        if (! is_array($destRows)) {
                            continue;
                        }
                        foreach ($destRows as $row) {
                            if (! is_array($row) || ! isset($row['price']) || (int) $row['price'] <= 0) {
                                continue;
                            }
                            // cheap は往復キャッシュが多い。片道用途では return_at 付きを除外
                            if ($oneWay && ! empty($row['return_at'])) {
                                continue;
                            }
                            if (! $oneWay && empty($row['return_at'])) {
                                continue;
                            }
                            $row['source'] = 'cheap';
                            $rows[] = $row;
                        }
                    }
                    if ($rows !== []) {
                        $closest = $this->pickClosestRows($rows, $departOn, $oneWay ? null : $returnOn);

                        return $closest !== [] ? $closest : [$rows[0]];
                    }
                }
            }

            $cal = Http::timeout(25)
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

            if (! $cal->successful()) {
                return [];
            }

            $json = $cal->json();
            $data = is_array($json) ? ($json['data'] ?? []) : [];
            if (! is_array($data) || $data === []) {
                return [];
            }

            $rows = [];
            foreach ($data as $row) {
                if (! is_array($row) || ! isset($row['price']) || (int) $row['price'] <= 0) {
                    continue;
                }
                if ($oneWay && ! empty($row['return_at'])) {
                    continue;
                }
                if (! $oneWay && empty($row['return_at'])) {
                    continue;
                }
                $row['source'] = 'calendar';
                $rows[] = $row;
            }

            $closest = $this->pickClosestRows($rows, $departOn, $oneWay ? null : $returnOn);

            return $closest !== [] ? $closest : (isset($rows[0]) ? [$rows[0]] : []);
        } catch (\Throwable $e) {
            Log::warning('travel.travelpayouts_cheap_failed', ['message' => $e->getMessage()]);

            return [];
        }
    }

    private function sanitizeRoundTrip(?int $rt, ?int $owSum): ?int
    {
        if ($rt === null) {
            return null;
        }
        if ($owSum === null || $owSum <= 0) {
            return $rt;
        }
        $lo = (int) round($owSum * 0.55);
        $hi = (int) round($owSum * 1.45);
        if ($rt < $lo || $rt > $hi) {
            return null;
        }

        return $rt;
    }

    public function searchUrl(
        string $origin,
        string $destination,
        string $departOn,
        ?string $returnOn
    ): string {
        // Aviasales 検索ディープリンク（例: FUK0110MNL03111）
        $dep = Carbon::parse($departOn);
        $code = strtoupper($origin)
            .$dep->format('d')
            .$dep->format('m')
            .strtoupper($destination);
        if ($returnOn) {
            $ret = Carbon::parse($returnOn);
            $code .= $ret->format('d').$ret->format('m').'1';
        } else {
            $code .= '1';
        }

        $url = 'https://www.aviasales.com/search/'.$code;
        $projectId = $this->travelpayouts->projectId();
        if ($projectId !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?').'marker='.rawurlencode($projectId);
        }

        return $url;
    }

    /**
     * @return list<array{url: string, label: string, badge: string}>
     */
    public function confirmUrls(
        string $airlineCode,
        string $origin,
        string $destination,
        string $departOn,
        ?string $returnOn
    ): array {
        $items = [];
        $official = $this->airlineHomepage($airlineCode);
        if ($official !== null) {
            $items[] = [
                'url' => $official['url'],
                'label' => $official['label'],
                'badge' => __('公式'),
            ];
        }
        $items[] = [
            'url' => $this->searchUrl($origin, $destination, $departOn, $returnOn),
            'label' => __('比較サイト（Aviasales）'),
            'badge' => __('目安'),
        ];

        return $items;
    }

    /** @return array{url: string, label: string}|null */
    private function airlineHomepage(string $airlineCode): ?array
    {
        return match (strtoupper(trim($airlineCode))) {
            '5J' => ['url' => 'https://www.cebupacificair.com/en-JP', 'label' => 'Cebu Pacific'],
            'PR' => ['url' => 'https://www.philippineairlines.com/', 'label' => 'Philippine Airlines'],
            'NH', 'NQ' => ['url' => 'https://www.ana.co.jp/', 'label' => 'ANA'],
            'JL' => ['url' => 'https://www.jal.co.jp/', 'label' => 'JAL'],
            'GK' => ['url' => 'https://www.jetstar.com/jp/ja/home', 'label' => 'Jetstar'],
            'MM' => ['url' => 'https://www.flypeach.com/', 'label' => 'Peach'],
            'JW' => ['url' => 'https://www.vanilla-air.com/', 'label' => 'Vanilla Air'],
            'BC' => ['url' => 'https://www.skymark.co.jp/', 'label' => 'Skymark'],
            'TW' => ['url' => 'https://www.twayair.com/', 'label' => 'T\'way'],
            'LJ' => ['url' => 'https://www.jinair.com/', 'label' => 'Jin Air'],
            'VJ' => ['url' => 'https://www.vietjetair.com/', 'label' => 'Vietjet'],
            'AK', 'D7' => ['url' => 'https://www.airasia.com/', 'label' => 'AirAsia'],
            default => null,
        };
    }

    /** @deprecated Google Flights リンク互換用 */
    public function googleFlightsUrl(
        string $origin,
        string $destination,
        string $departOn,
        ?string $returnOn,
        string $currency = 'PHP'
    ): string {
        return $this->searchUrl($origin, $destination, $departOn, $returnOn);
    }
}
