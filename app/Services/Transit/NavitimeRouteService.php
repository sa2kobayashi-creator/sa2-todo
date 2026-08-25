<?php

namespace App\Services\Transit;

use App\Services\GoogleMapsConfigService;
use App\Services\IntegrationUsageService;
use App\Services\NavitimeConfigService;
use App\Services\Transit\Raptor\ItineraryScorer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * NAVITIME のトータルナビ検索を、RAPTOR と同じ itinerary 形式に変換する。
 *
 * 出発地・到着地はアプリ側では地名の文字列なので、緯度経度に直してから渡す。
 */
class NavitimeRouteService
{
    private const POINT_CACHE_HOURS = 24;

    public function __construct(
        private NavitimeConfigService $navitime,
        private GoogleMapsConfigService $googleMaps,
        private IntegrationUsageService $usage,
    ) {}

    public function isReady(): bool
    {
        return $this->navitime->isReady();
    }

    /**
     * @param array{
     *   from: string,
     *   to: string,
     *   departureAt?: string,
     *   timeType?: string,
     *   preference?: string,
     *   limit?: int
     * } $query
     * @return array{ok: bool, engine?: string, message?: string, itineraries: list<array<string, mixed>>}
     */
    public function search(array $query): array
    {
        $fromName = trim((string) ($query['from'] ?? ''));
        $toName = trim((string) ($query['to'] ?? ''));
        if ($fromName === '' || $toName === '') {
            return ['ok' => false, 'message' => __('出発地と到着地を入力してください'), 'itineraries' => []];
        }

        $from = $this->resolvePoint($fromName);
        $to = $this->resolvePoint($toName);
        if ($from === null || $to === null) {
            $missing = $from === null ? $fromName : $toName;

            return [
                'ok' => false,
                'message' => __('「:place」の場所を特定できませんでした。駅名や住所で入力してください。', ['place' => $missing]),
                'itineraries' => [],
            ];
        }

        $params = [
            'start' => $from['lat'].','.$from['lon'],
            'goal' => $to['lat'].','.$to['lon'],
            'datum' => 'wgs84',
            'coord_unit' => 'degree',
            'limit' => max(1, min(10, (int) ($query['limit'] ?? 5))),
            'order' => $this->orderFor((string) ($query['preference'] ?? ItineraryScorer::PREF_FASTEST)),
        ];
        $params += $this->timeParams((string) ($query['departureAt'] ?? ''), (string) ($query['timeType'] ?? 'departure'));

        $result = $this->navitime->get('route_transit', $params);
        if (! $result['ok']) {
            return ['ok' => false, 'message' => $result['message'], 'itineraries' => []];
        }

        $this->usage->increment('navitime');

        $items = is_array($result['data']['items'] ?? null) ? $result['data']['items'] : [];
        $itineraries = [];
        foreach ($items as $item) {
            $converted = is_array($item) ? $this->toItinerary($item) : null;
            if ($converted !== null) {
                $itineraries[] = $converted;
            }
        }

        if ($itineraries === []) {
            return ['ok' => false, 'message' => __('条件に合う経路が見つかりませんでした。'), 'itineraries' => []];
        }

        foreach ($itineraries as $i => &$itinerary) {
            $itinerary['rank'] = $i + 1;
        }
        unset($itinerary);

        return [
            'ok' => true,
            'engine' => 'NAVITIME',
            'preference' => ItineraryScorer::PREF_FASTEST,
            'fromStop' => ['id' => '', 'name' => $from['name'] !== '' ? $from['name'] : $fromName],
            'toStop' => ['id' => '', 'name' => $to['name'] !== '' ? $to['name'] : $toName],
            'itineraries' => $itineraries,
        ];
    }

    /**
     * 地名 → 緯度経度。NAVITIME の地点検索が使えればそれを、なければ Google ジオコーディングを使う。
     *
     * @return array{lat: float, lon: float, name: string}|null
     */
    public function resolvePoint(string $word): ?array
    {
        $word = trim($word);
        if ($word === '') {
            return null;
        }

        if (preg_match('/^\s*(-?\d{1,3}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $word, $m)) {
            return ['lat' => (float) $m[1], 'lon' => (float) $m[2], 'name' => $word];
        }

        $cacheKey = 'navitime:point:'.md5(mb_strtolower($word));
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['lat'], $cached['lon'])) {
            return $cached;
        }

        $point = $this->resolveByNavitime($word) ?? $this->resolveByGoogle($word);
        if ($point !== null) {
            Cache::put($cacheKey, $point, now()->addHours(self::POINT_CACHE_HOURS));
        }

        return $point;
    }

    /** @return array{lat: float, lon: float, name: string}|null */
    private function resolveByNavitime(string $word): ?array
    {
        if ($this->navitime->nodeBaseUrl() === '') {
            return null;
        }

        $result = $this->navitime->get('transport_node', [
            'word' => $word,
            'limit' => 1,
            'datum' => 'wgs84',
            'coord_unit' => 'degree',
        ], true);
        if (! $result['ok']) {
            return null;
        }

        $item = $result['data']['items'][0] ?? null;
        if (! is_array($item)) {
            return null;
        }
        $lat = $item['coord']['lat'] ?? null;
        $lon = $item['coord']['lon'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lon)) {
            return null;
        }

        return [
            'lat' => round((float) $lat, 6),
            'lon' => round((float) $lon, 6),
            'name' => (string) ($item['name'] ?? $word),
        ];
    }

    /** @return array{lat: float, lon: float, name: string}|null */
    private function resolveByGoogle(string $word): ?array
    {
        $key = $this->googleMaps->apiKey();
        if ($key === '') {
            return null;
        }

        try {
            $res = Http::timeout(10)->acceptJson()->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address' => $word,
                'language' => 'ja',
                'region' => 'jp',
                'key' => $key,
            ]);
        } catch (\Throwable) {
            return null;
        }

        $json = $res->json();
        if (! is_array($json) || ($json['status'] ?? '') !== 'OK') {
            return null;
        }

        $location = $json['results'][0]['geometry']['location'] ?? null;
        if (! is_array($location) || ! isset($location['lat'], $location['lng'])) {
            return null;
        }

        return [
            'lat' => round((float) $location['lat'], 6),
            'lon' => round((float) $location['lng'], 6),
            'name' => $word,
        ];
    }

    /** @return array<string, string> */
    private function timeParams(string $departureAt, string $timeType): array
    {
        $stamp = $this->normalizeDateTime($departureAt);

        return $timeType === 'arrival'
            ? ['goal_time' => $stamp]
            : ['start_time' => $stamp];
    }

    private function normalizeDateTime(string $raw): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/', trim($raw), $m)) {
            return $m[1].'-'.$m[2].'-'.$m[3].'T'.$m[4].':'.$m[5].':00';
        }

        return now()->format('Y-m-d\TH:i:00');
    }

    private function orderFor(string $preference): string
    {
        return match ($preference) {
            ItineraryScorer::PREF_CHEAPEST => 'fare',
            ItineraryScorer::PREF_FEWEST_TRANSFERS => 'transit',
            default => 'time',
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function toItinerary(array $item): ?array
    {
        $move = $item['summary']['move'] ?? null;
        if (! is_array($move)) {
            return null;
        }

        $departure = $this->clock((string) ($move['from_time'] ?? ''));
        $arrival = $this->clock((string) ($move['to_time'] ?? ''));
        $durationSec = max(0, (int) ($move['time'] ?? 0)) * 60;
        $fare = $this->fareOf($move['fare'] ?? null);

        [$legs, $waitSec, $usesNishitetsuBus] = $this->buildLegs($item['sections'] ?? []);
        if ($legs === []) {
            return null;
        }

        $transfers = max(0, (int) ($move['transit_count'] ?? 0));
        $names = [];
        foreach ($legs as $leg) {
            if (($leg['type'] ?? '') === 'ride' && ($leg['routeName'] ?? '') !== '') {
                $names[] = $leg['routeName'];
            }
        }
        $names = array_values(array_unique($names));
        $summary = $names !== [] ? implode(' → ', $names) : '経路';
        if ($transfers > 0) {
            $summary .= '（乗換'.$transfers.'回）';
        }

        return [
            'departureTime' => $departure,
            'arrivalTime' => $arrival,
            'durationSec' => $durationSec,
            'durationLabel' => $this->durationLabel($durationSec),
            'waitSec' => $waitSec,
            'waitLabel' => $this->durationLabel($waitSec),
            'walkSec' => (int) array_sum(array_map(
                fn (array $leg) => ($leg['type'] ?? '') === 'walk' ? (int) ($leg['durationSec'] ?? 0) : 0,
                $legs
            )),
            'transfers' => $transfers,
            'fare' => $fare,
            'fareLabel' => $fare > 0 ? '¥'.number_format($fare) : __('運賃情報なし'),
            'usesNishitetsuBus' => $usesNishitetsuBus,
            'legs' => $legs,
            'summary' => $summary,
            'signature' => md5($departure.$arrival.implode('|', $names)),
        ];
    }

    /** @return array{0: list<array<string, mixed>>, 1: int, 2: bool} */
    private function buildLegs(mixed $sections): array
    {
        if (! is_array($sections)) {
            return [[], 0, false];
        }
        $sections = array_values($sections);

        $legs = [];
        $waitSec = 0;
        $usesNishitetsuBus = false;
        $pendingFrom = '';
        $previousArrival = null;

        foreach ($sections as $index => $section) {
            if (! is_array($section)) {
                continue;
            }
            if (($section['type'] ?? '') === 'point') {
                $pendingFrom = (string) ($section['name'] ?? $pendingFrom);

                continue;
            }
            if (($section['type'] ?? '') !== 'move') {
                continue;
            }

            $to = '';
            for ($i = $index + 1; $i < count($sections); $i++) {
                if (is_array($sections[$i]) && ($sections[$i]['type'] ?? '') === 'point') {
                    $to = (string) ($sections[$i]['name'] ?? '');
                    break;
                }
            }

            $mode = (string) ($section['move'] ?? '');
            $seconds = max(0, (int) ($section['time'] ?? 0)) * 60;
            $fromTime = (string) ($section['from_time'] ?? '');
            $toTime = (string) ($section['to_time'] ?? '');

            if ($mode === 'walk') {
                $legs[] = [
                    'type' => 'walk',
                    'from' => $pendingFrom,
                    'to' => $to,
                    'durationSec' => $seconds,
                    'label' => '徒歩',
                ];
                $previousArrival = $this->timestamp($toTime) ?? $previousArrival;
                $pendingFrom = $to;

                continue;
            }

            $transport = is_array($section['transport'] ?? null) ? $section['transport'] : [];
            $routeName = trim((string) ($transport['self_name'] ?? ''));
            if ($routeName === '') {
                $routeName = trim((string) ($transport['name'] ?? ''));
            }
            if ($routeName === '') {
                $routeName = trim((string) ($section['line_name'] ?? ''));
            }
            if (str_contains($routeName, '西鉄') || str_contains((string) ($transport['company']['name'] ?? ''), '西鉄')) {
                $usesNishitetsuBus = true;
            }

            $board = $this->timestamp($fromTime);
            $wait = ($board !== null && $previousArrival !== null) ? max(0, $board - $previousArrival) : 0;
            $waitSec += $wait;

            $legs[] = [
                'type' => 'ride',
                'routeName' => $routeName !== '' ? $routeName : '公共交通',
                'mode' => $mode,
                'from' => $pendingFrom,
                'to' => $to,
                'boardTime' => $this->clock($fromTime),
                'alightTime' => $this->clock($toTime),
                'waitSec' => $wait,
                'durationSec' => $seconds,
                'label' => $routeName,
            ];
            $previousArrival = $this->timestamp($toTime) ?? $previousArrival;
            $pendingFrom = $to;
        }

        return [$legs, $waitSec, $usesNishitetsuBus];
    }

    private function fareOf(mixed $fare): int
    {
        if (! is_array($fare)) {
            return 0;
        }
        foreach (['unit_0', 'unit_48'] as $key) {
            if (isset($fare[$key]) && is_numeric($fare[$key])) {
                return (int) round((float) $fare[$key]);
            }
        }

        return 0;
    }

    private function timestamp(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $time = strtotime($value);

        return $time === false ? null : $time;
    }

    private function clock(string $value): string
    {
        $time = $this->timestamp($value);

        return $time === null ? '' : date('H:i', $time);
    }

    private function durationLabel(int $sec): string
    {
        $sec = max(0, $sec);
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);

        return $h > 0 ? $h.'時間'.$m.'分' : $m.'分';
    }
}
