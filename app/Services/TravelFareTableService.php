<?php

namespace App\Services;

use App\Support\AirlineName;
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
        string $origin = '',
        string $destination = '',
        string $airlineCode = '',
        string $currency = 'JPY',
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

        $origin = strtoupper(trim($origin));
        $destination = strtoupper(trim($destination));
        if ($origin === '' || $destination === '') {
            throw new \InvalidArgumentException(__('出発空港と到着空港を入力してください。'));
        }
        $filterAirline = strtoupper(trim($airlineCode));
        $currency = strtoupper($currency) === 'PHP' ? 'PHP' : 'JPY';

        $warnings = [];
        $notes = [
            __('Travelpayouts の月次キャッシュから期間内の目安運賃を集計しています。空席保証ではありません。'),
            __('日によってデータがない日は「—」になります。予約前に公式または比較サイトで再確認してください。'),
            __('航空会社を空欄にすると、同じ日でも複数社の目安を候補に出します。'),
        ];

        if ($mode === 'ow') {
            $options = $this->legOptionsByDate($origin, $destination, $departFrom, $departTo, $filterAirline, $warnings);
            $outbound = $this->cheapestOptionPerDate($options, $currency);
            $rows = $this->buildOneWayRows($departFrom, $departTo, $outbound, $currency);
            $cheapest = $this->decorateFlights(
                $this->pickDiverseCandidates($this->flattenLegOptions($options), $currency),
                $origin,
                $destination
            );

            if (! array_filter($rows, fn (array $row) => ! empty($row['hasData']))) {
                $warnings[] = __('この期間の片道キャッシュは見つかりませんでした。表は表示していますが、金額が入っている日がありません。');
            }

            $filledCount = count(array_filter($rows, fn (array $row) => ! empty($row['isFilled'])));
            if ($filledCount > 0) {
                $warnings[] = __('データがない日は、近い日のキャッシュから補完して表示しています（近似）。');
            }

            app(IntegrationUsageService::class)->increment('travelpayouts');

            return [
                'mode' => 'ow',
                'origin' => $origin,
                'destination' => $destination,
                'airlineCode' => $filterAirline,
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

        $outOptions = $this->legOptionsByDate($origin, $destination, $departFrom, $departTo, $filterAirline, $warnings);
        $inOptions = $this->legOptionsByDate($destination, $origin, $returnFromDate, $returnToDate, $filterAirline, $warnings);
        $outbound = $this->cheapestOptionPerDate($outOptions, $currency);
        $inbound = $this->cheapestOptionPerDate($inOptions, $currency);
        $rtOptions = $this->roundTripCalendarOptions(
            $origin,
            $destination,
            $departFrom,
            $departTo,
            $returnFromDate,
            $returnToDate,
            $filterAirline,
            $warnings
        );
        $rtPairs = $this->cheapestRtPairByDates($rtOptions, $currency);

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

        $candidateSource = $this->flattenRtOptions($rtOptions);
        if ($candidateSource === [] || count($this->uniqueAirlines($candidateSource)) < 2) {
            $candidateSource = array_merge($candidateSource, $matrix['cells'] ?? []);
        }

        app(IntegrationUsageService::class)->increment('travelpayouts');

        return [
            'mode' => 'rt',
            'origin' => $origin,
            'destination' => $destination,
            'airlineCode' => $filterAirline,
            'departFrom' => $departFrom,
            'departTo' => $departTo,
            'returnFrom' => $returnFromDate,
            'returnTo' => $returnToDate,
            'currency' => $currency,
            'rows' => [],
            'matrix' => $matrix,
            'cheapest' => $this->decorateFlights(
                $this->pickDiverseCandidates($candidateSource, $currency),
                $origin,
                $destination
            ),
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
     * @return array<string, array<string, array{pricePhp: int|null, priceJpy: int|null, source: string}>>
     */
    private function legOptionsByDate(
        string $origin,
        string $destination,
        string $from,
        string $to,
        string $filterAirline,
        array &$warnings
    ): array {
        $byDate = [];
        foreach (['PHP', 'JPY'] as $currency) {
            $this->absorbPriceRows(
                $byDate,
                $this->requestLatestPrices($origin, $destination, $currency),
                $currency,
                $from,
                $to,
                $filterAirline
            );
        }
        foreach ($this->monthsInRange($from, $to) as $month) {
            foreach (['PHP', 'JPY'] as $currency) {
                $this->absorbPriceRows(
                    $byDate,
                    $this->requestPricesForDatesMonth($origin, $destination, $month, $currency, true, $filterAirline),
                    $currency,
                    $from,
                    $to,
                    $filterAirline
                );
                $this->absorbPriceRows(
                    $byDate,
                    $this->requestMonthMatrixMonth($origin, $destination, $month, $currency, false),
                    $currency,
                    $from,
                    $to,
                    $filterAirline
                );
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

    /**
     * @param  array<string, array<string, array{pricePhp: int|null, priceJpy: int|null, source: string}>>  $byDate
     * @param  list<array{price: int, departure_at: string, airline?: string, source?: string}>  $rows
     */
    private function absorbPriceRows(
        array &$byDate,
        array $rows,
        string $currency,
        string $from,
        string $to,
        string $filterAirline
    ): void {
        $priceKey = $currency === 'JPY' ? 'priceJpy' : 'pricePhp';
        foreach ($rows as $row) {
            $date = substr((string) ($row['departure_at'] ?? ''), 0, 10);
            if ($date === '' || $date < $from || $date > $to) {
                continue;
            }
            $airline = $this->airlineFromRow($row);
            if ($filterAirline !== '' && $airline !== '' && $airline !== $filterAirline) {
                continue;
            }
            $slot = $airline !== '' ? $airline : '_';
            if (! isset($byDate[$date][$slot])) {
                $byDate[$date][$slot] = [
                    'pricePhp' => null,
                    'priceJpy' => null,
                    'source' => (string) ($row['source'] ?? ''),
                ];
            }
            $price = (int) ($row['price'] ?? 0);
            if ($price <= 0) {
                continue;
            }
            $current = $byDate[$date][$slot][$priceKey];
            if ($current === null || $price < $current) {
                $byDate[$date][$slot][$priceKey] = $price;
                $byDate[$date][$slot]['source'] = (string) ($row['source'] ?? $byDate[$date][$slot]['source']);
            }
        }
    }

    /**
     * @param  array<string, array<string, array{pricePhp: int|null, priceJpy: int|null, source: string}>>  $options
     * @return array<string, array{pricePhp: int|null, priceJpy: int|null, airline: string, source: string}>
     */
    private function cheapestOptionPerDate(array $options, string $currency): array
    {
        $priceKey = $currency === 'JPY' ? 'priceJpy' : 'pricePhp';
        $byDate = [];
        foreach ($options as $date => $airlines) {
            if (! is_array($airlines)) {
                continue;
            }
            $best = null;
            $bestAirline = '';
            $bestPrice = null;
            foreach ($airlines as $airline => $leg) {
                if (! is_array($leg)) {
                    continue;
                }
                $price = $leg[$priceKey] ?? null;
                if ($price === null) {
                    $price = $leg['priceJpy'] ?? $leg['pricePhp'] ?? null;
                }
                if ($price === null) {
                    continue;
                }
                if ($bestPrice === null || (int) $price < $bestPrice) {
                    $best = $leg;
                    $bestAirline = (string) $airline;
                    $bestPrice = (int) $price;
                }
            }
            if ($best !== null) {
                $byDate[(string) $date] = [
                    'pricePhp' => $best['pricePhp'] ?? null,
                    'priceJpy' => $best['priceJpy'] ?? null,
                    'airline' => $bestAirline === '_' ? '' : $bestAirline,
                    'source' => (string) ($best['source'] ?? ''),
                ];
            }
        }
        ksort($byDate);

        return $byDate;
    }

    /**
     * @param  array<string, array<string, array{pricePhp: int|null, priceJpy: int|null, source: string}>>  $options
     * @return list<array<string, mixed>>
     */
    private function flattenLegOptions(array $options): array
    {
        $items = [];
        foreach ($options as $date => $airlines) {
            if (! is_array($airlines)) {
                continue;
            }
            foreach ($airlines as $airline => $leg) {
                if (! is_array($leg)) {
                    continue;
                }
                $items[] = [
                    'departOn' => (string) $date,
                    'airline' => ((string) $airline) === '_' ? '' : strtoupper((string) $airline),
                    'pricePhp' => $leg['pricePhp'] ?? null,
                    'priceJpy' => $leg['priceJpy'] ?? null,
                    'source' => $leg['source'] ?? '',
                ];
            }
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function pickDiverseCandidates(array $items, string $currency, int $limit = 16, int $perAirline = 4): array
    {
        $priced = array_values(array_filter($items, function (array $row) use ($currency) {
            $price = $currency === 'JPY' ? ($row['priceJpy'] ?? null) : ($row['pricePhp'] ?? null);

            return $price !== null && (int) $price > 0 && empty($row['isFilled']);
        }));
        usort($priced, function (array $a, array $b) use ($currency) {
            $pa = (int) ($currency === 'JPY' ? $a['priceJpy'] : $a['pricePhp']);
            $pb = (int) ($currency === 'JPY' ? $b['priceJpy'] : $b['pricePhp']);
            if ($pa === $pb) {
                return strcmp((string) ($a['departOn'] ?? ''), (string) ($b['departOn'] ?? ''));
            }

            return $pa <=> $pb;
        });

        $picked = [];
        $seen = [];
        $counts = [];
        $fingerprints = [];
        $fingerprintOf = static function (array $row): string {
            $airline = strtoupper(trim((string) ($row['airline'] ?? ''))) ?: '_';

            return $airline.'|'.($row['departOn'] ?? '').'|'.($row['returnOn'] ?? '');
        };

        foreach ($priced as $row) {
            $airline = strtoupper(trim((string) ($row['airline'] ?? ''))) ?: '_';
            if (isset($seen[$airline])) {
                continue;
            }
            $seen[$airline] = true;
            $counts[$airline] = 1;
            $picked[] = $row;
            $fingerprints[$fingerprintOf($row)] = true;
            if (count($picked) >= $limit) {
                return $picked;
            }
        }

        foreach ($priced as $row) {
            $fp = $fingerprintOf($row);
            if (isset($fingerprints[$fp])) {
                continue;
            }
            $airline = strtoupper(trim((string) ($row['airline'] ?? ''))) ?: '_';
            if (($counts[$airline] ?? 0) >= $perAirline) {
                continue;
            }
            $counts[$airline] = ($counts[$airline] ?? 0) + 1;
            $picked[] = $row;
            $fingerprints[$fp] = true;
            if (count($picked) >= $limit) {
                break;
            }
        }

        usort($picked, function (array $a, array $b) use ($currency) {
            $pa = (int) ($currency === 'JPY' ? $a['priceJpy'] : $a['pricePhp']);
            $pb = (int) ($currency === 'JPY' ? $b['priceJpy'] : $b['pricePhp']);
            if ($pa === $pb) {
                return strcmp((string) ($a['departOn'] ?? ''), (string) ($b['departOn'] ?? ''));
            }

            return $pa <=> $pb;
        });

        return $picked;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function uniqueAirlines(array $items): array
    {
        $codes = [];
        foreach ($items as $item) {
            $code = strtoupper(trim((string) ($item['airline'] ?? '')));
            if ($code !== '') {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }

    /** @param  array<string, mixed>  $row */
    private function airlineFromRow(array $row): string
    {
        $code = strtoupper(trim((string) ($row['airline'] ?? '')));
        if ($code !== '') {
            return $code;
        }
        $airlines = $row['airlines'] ?? null;
        if (is_array($airlines) && $airlines !== []) {
            return strtoupper(trim((string) $airlines[0]));
        }

        return '';
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
                    'airline' => strtoupper((string) (is_array($rt)
                        ? ($rt['airline'] ?? '')
                        : (is_array($out) ? ($out['airline'] ?? '') : ''))),
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
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function decorateFlights(array $items, string $origin, string $destination): array
    {
        $quote = app(TravelFareQuoteService::class);

        return array_map(function (array $item) use ($quote, $origin, $destination) {
            $code = strtoupper(trim((string) ($item['airline'] ?? '')));
            $item['airline'] = $code;
            $item['airlineLabel'] = AirlineName::label($code);
            $departOn = (string) ($item['departOn'] ?? '');
            if ($departOn === '') {
                return $item;
            }
            $returnOn = ! empty($item['returnOn']) ? (string) $item['returnOn'] : null;
            try {
                $item['searchUrl'] = $quote->searchUrl($origin, $destination, $departOn, $returnOn);
                $links = $quote->confirmUrls($code, $origin, $destination, $departOn, $returnOn);
                $official = collect($links)->first(fn (array $link) => ($link['badge'] ?? '') === __('公式'));
                $item['officialUrl'] = is_array($official) ? (string) ($official['url'] ?? '') : '';
                $item['officialLabel'] = is_array($official) ? (string) ($official['label'] ?? '') : '';
            } catch (\Throwable) {
                $item['searchUrl'] = $item['searchUrl'] ?? '';
                $item['officialUrl'] = '';
                $item['officialLabel'] = '';
            }

            return $item;
        }, $items);
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
     * @return array<string, array{departOn: string, returnOn: string, pricePhp: int|null, priceJpy: int|null, airline: string, source: string}>
     */
    private function roundTripCalendarOptions(
        string $origin,
        string $destination,
        string $departFrom,
        string $departTo,
        string $returnFrom,
        string $returnTo,
        string $filterAirline,
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

                    $airline = $this->airlineFromRow($row);
                    if ($filterAirline !== '' && $airline !== '' && $airline !== $filterAirline) {
                        continue;
                    }
                    $slot = $airline !== '' ? $airline : '_';
                    $key = $departOn.'|'.$returnOn.'|'.$slot;
                    if (! isset($pairs[$key])) {
                        $pairs[$key] = [
                            'departOn' => $departOn,
                            'returnOn' => $returnOn,
                            'pricePhp' => null,
                            'priceJpy' => null,
                            'airline' => $slot === '_' ? '' : $slot,
                            'source' => 'calendar',
                        ];
                    }
                    $price = (int) $row['price'];
                    $priceKey = $curr === 'JPY' ? 'priceJpy' : 'pricePhp';
                    $current = $pairs[$key][$priceKey];
                    if ($current === null || $price < $current) {
                        $pairs[$key][$priceKey] = $price;
                    }
                }
            }
        }

        if ($pairs !== []) {
            $warnings[] = __('往復一括のキャッシュがある組み合わせは calendar 価格を優先しています。');
        }

        return $pairs;
    }

    /**
     * @param  array<string, array{departOn: string, returnOn: string, pricePhp: int|null, priceJpy: int|null, airline: string, source: string}>  $options
     * @return array<string, array{pricePhp: int|null, priceJpy: int|null, airline: string, source: string}>
     */
    private function cheapestRtPairByDates(array $options, string $currency): array
    {
        $priceKey = $currency === 'JPY' ? 'priceJpy' : 'pricePhp';
        $pairs = [];
        foreach ($options as $option) {
            $dateKey = $option['departOn'].'|'.$option['returnOn'];
            $price = $option[$priceKey] ?? null;
            if ($price === null) {
                $price = $option['priceJpy'] ?? $option['pricePhp'] ?? null;
            }
            if ($price === null) {
                continue;
            }
            $current = $pairs[$dateKey][$priceKey] ?? null;
            if ($current === null || (int) $price < (int) $current) {
                $pairs[$dateKey] = [
                    'pricePhp' => $option['pricePhp'] ?? null,
                    'priceJpy' => $option['priceJpy'] ?? null,
                    'airline' => (string) ($option['airline'] ?? ''),
                    'source' => 'calendar',
                ];
            }
        }

        return $pairs;
    }

    /**
     * @param  array<string, array{departOn: string, returnOn: string, pricePhp: int|null, priceJpy: int|null, airline: string, source: string}>  $options
     * @return list<array<string, mixed>>
     */
    private function flattenRtOptions(array $options): array
    {
        $items = [];
        foreach ($options as $option) {
            $items[] = [
                'departOn' => $option['departOn'],
                'returnOn' => $option['returnOn'],
                'airline' => $option['airline'],
                'pricePhp' => $option['pricePhp'],
                'priceJpy' => $option['priceJpy'],
                'source' => $option['source'] ?? 'calendar',
            ];
        }

        return $items;
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
        bool $oneWay,
        string $filterAirline = ''
    ): array {
        $token = $this->travelpayouts->token();
        $base = $this->travelpayouts->baseUrl();
        $currency = strtoupper($currency);
        $market = $currency === 'JPY'
            ? $this->travelpayouts->marketJpy()
            : $this->travelpayouts->marketPhp();

        try {
            $attempts = $this->travelpayouts->directOnly() ? [true, false] : [false];
            $byKey = [];

            foreach ($attempts as $direct) {
                $gotAny = false;
                for ($page = 1; $page <= 3; $page++) {
                    $query = [
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
                        'limit' => 300,
                        'page' => $page,
                        'token' => $token,
                    ];
                    if ($filterAirline !== '') {
                        $query['airline'] = $filterAirline;
                    }

                    $response = Http::timeout(25)
                        ->withHeaders([
                            'X-Access-Token' => $token,
                            'Accept' => 'application/json',
                        ])
                        ->get($base.'/aviasales/v3/prices_for_dates', $query);

                    if (! $response->successful()) {
                        continue;
                    }

                    $json = $response->json();
                    if (! is_array($json) || empty($json['success'])) {
                        continue;
                    }

                    $data = $json['data'] ?? [];
                    if (! is_array($data) || $data === []) {
                        break;
                    }

                    $pageCount = 0;
                    foreach ($data as $row) {
                        if (! is_array($row) || ! isset($row['price']) || (int) $row['price'] <= 0) {
                            continue;
                        }
                        $dep = substr((string) ($row['departure_at'] ?? ''), 0, 10);
                        if ($dep === '' || ! str_starts_with($dep, $month)) {
                            continue;
                        }
                        $price = (int) $row['price'];
                        $airline = $this->airlineFromRow($row);
                        $key = $dep.'|'.($airline !== '' ? $airline : '_');
                        $gotAny = true;
                        $pageCount++;
                        if (! isset($byKey[$key]) || $price < $byKey[$key]['price']) {
                            $byKey[$key] = [
                                'price' => $price,
                                'departure_at' => $dep,
                                'airline' => $airline,
                                'source' => 'prices_for_dates',
                            ];
                        }
                    }

                    $airlineCount = count($this->uniqueAirlines(array_values($byKey)));
                    if ($pageCount < 300 || $filterAirline !== '' || $airlineCount >= 6) {
                        break;
                    }
                }

                if ($gotAny && ($filterAirline !== '' || count($this->uniqueAirlines(array_values($byKey))) >= 2)) {
                    break;
                }
                if ($gotAny && ! $this->travelpayouts->directOnly()) {
                    break;
                }
            }

            return array_values($byKey);
        } catch (\Throwable $e) {
            Log::warning('travel.fare_table_prices_for_dates_failed', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return list<array{price: int, departure_at: string, airline: string, source: string}>
     */
    private function requestLatestPrices(string $origin, string $destination, string $currency): array
    {
        $token = $this->travelpayouts->token();
        $base = $this->travelpayouts->baseUrl();
        $currency = strtoupper($currency);

        try {
            $response = Http::timeout(25)
                ->withHeaders([
                    'X-Access-Token' => $token,
                    'Accept' => 'application/json',
                ])
                ->get($base.'/v2/prices/latest', [
                    'origin' => $origin,
                    'destination' => $destination,
                    'currency' => strtolower($currency),
                    'period_type' => 'year',
                    'page' => 1,
                    'limit' => 100,
                    'show_to_affiliates' => 'true',
                    'sorting' => 'price',
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
                $dep = substr((string) ($row['depart_date'] ?? $row['departure_at'] ?? ''), 0, 10);
                if ($price <= 0 || $dep === '') {
                    continue;
                }
                if ($this->travelpayouts->directOnly() && isset($row['number_of_changes']) && (int) $row['number_of_changes'] > 0) {
                    continue;
                }
                $rows[] = [
                    'price' => $price,
                    'departure_at' => $dep,
                    'airline' => $this->airlineFromRow($row),
                    'source' => 'latest',
                ];
            }

            return $rows;
        } catch (\Throwable $e) {
            Log::warning('travel.fare_table_latest_failed', ['message' => $e->getMessage()]);

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
                    'airline' => $this->airlineFromRow($row),
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
                    'airline' => $this->airlineFromRow($row),
                ];
            }

            return $rows;
        } catch (\Throwable $e) {
            Log::warning('travel.fare_table_calendar_failed', ['message' => $e->getMessage()]);

            return [];
        }
    }
}
