<?php

namespace App\Services\Transit;

use App\Services\GoogleRoutesConfigService;
use App\Services\IntegrationUsageService;
use App\Services\Transit\Raptor\ItineraryScorer;

/**
 * Google Maps Routes API の公共交通ルートを、画面共通の itinerary 形式に変換する。
 */
class GoogleRoutesRouteService
{
    public function __construct(
        private GoogleRoutesConfigService $routes,
        private IntegrationUsageService $usage,
    ) {}

    public function isReady(): bool
    {
        return $this->routes->isReady();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{ok: bool, engine?: string, message?: string, itineraries: list<array<string, mixed>>}
     */
    public function search(array $query): array
    {
        $from = trim((string) ($query['from'] ?? ''));
        $to = trim((string) ($query['to'] ?? ''));
        if ($from === '' || $to === '') {
            return ['ok' => false, 'message' => __('出発地と到着地を入力してください'), 'itineraries' => []];
        }

        $body = [
            'origin' => $this->place($from),
            'destination' => $this->place($to),
            'travelMode' => 'TRANSIT',
            'computeAlternativeRoutes' => true,
            'languageCode' => 'ja',
            'regionCode' => 'JP',
            'transitPreferences' => [
                'routingPreference' => ($query['preference'] ?? '') === ItineraryScorer::PREF_FEWEST_TRANSFERS
                    ? 'FEWER_TRANSFERS'
                    : 'LESS_WALKING',
            ],
        ];
        $body += $this->timeParams((string) ($query['departureAt'] ?? ''), (string) ($query['timeType'] ?? 'departure'));

        $result = $this->routes->computeRoutes($body);
        if (! $result['ok']) {
            return ['ok' => false, 'message' => $result['message'], 'itineraries' => []];
        }

        $this->usage->increment('google_routes');

        $routes = is_array($result['data']['routes'] ?? null) ? $result['data']['routes'] : [];
        $limit = max(1, min(10, (int) ($query['limit'] ?? 5)));
        $itineraries = [];
        foreach (array_slice($routes, 0, $limit) as $route) {
            if (! is_array($route)) {
                continue;
            }
            $converted = $this->toItinerary($route);
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
            'engine' => 'Google Maps Routes',
            'preference' => (string) ($query['preference'] ?? ItineraryScorer::PREF_FASTEST),
            'fromStop' => ['id' => '', 'name' => $from],
            'toStop' => ['id' => '', 'name' => $to],
            'itineraries' => $itineraries,
        ];
    }

    /** @return array<string, mixed> */
    private function place(string $word): array
    {
        if (preg_match('/^\s*(-?\d{1,3}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $word, $m)) {
            return [
                'location' => [
                    'latLng' => [
                        'latitude' => (float) $m[1],
                        'longitude' => (float) $m[2],
                    ],
                ],
            ];
        }

        return ['address' => $word];
    }

    /** @return array<string, mixed> */
    private function timeParams(string $departureAt, string $timeType): array
    {
        $stamp = $this->rfc3339($departureAt);

        return $timeType === 'arrival'
            ? ['arrivalTime' => $stamp]
            : ['departureTime' => $stamp];
    }

    private function rfc3339(string $raw): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/', trim($raw), $m)) {
            return sprintf('%s-%s-%sT%s:%s:00+09:00', $m[1], $m[2], $m[3], $m[4], $m[5]);
        }

        return now()->timezone('Asia/Tokyo')->format('Y-m-d\TH:i:sP');
    }

    /** @param array<string, mixed> $route */
    private function toItinerary(array $route): ?array
    {
        $legs = [];
        $waitSec = 0;
        $previousArrival = null;

        foreach (TransitItinerary::asList($route['legs'] ?? []) as $leg) {
            if (! is_array($leg)) {
                continue;
            }
            foreach (TransitItinerary::asList($leg['steps'] ?? []) as $step) {
                if (! is_array($step)) {
                    continue;
                }
                $converted = $this->stepToLeg($step, $previousArrival);
                if ($converted === null) {
                    continue;
                }
                $waitSec += (int) ($converted['waitSec'] ?? 0);
                if (($converted['type'] ?? '') === 'ride') {
                    $alight = TransitItinerary::timestamp((string) ($step['transitDetails']['stopDetails']['arrivalTime'] ?? ''));
                    $previousArrival = $alight ?? $previousArrival;
                }
                $legs[] = $converted;
            }
        }

        $transfers = max(0, count(array_filter($legs, fn (array $leg) => ($leg['type'] ?? '') === 'ride')) - 1);

        return TransitItinerary::assemble($legs, $transfers, $this->fareOf($route), $waitSec);
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>|null
     */
    private function stepToLeg(array $step, ?int $previousArrival): ?array
    {
        $seconds = TransitItinerary::secondsFromGoogle($step['staticDuration'] ?? null);
        if ($seconds < 1) {
            $seconds = TransitItinerary::secondsFromGoogle($step['duration'] ?? null);
        }
        $mode = strtoupper((string) ($step['travelMode'] ?? ''));

        if ($mode === 'WALK' || $mode === 'WALKING') {
            return [
                'type' => 'walk',
                'from' => '',
                'to' => '',
                'durationSec' => $seconds,
                'label' => '徒歩',
            ];
        }

        $details = is_array($step['transitDetails'] ?? null) ? $step['transitDetails'] : [];
        $stops = is_array($details['stopDetails'] ?? null) ? $details['stopDetails'] : [];
        $line = is_array($details['transitLine'] ?? null) ? $details['transitLine'] : [];
        $routeName = trim((string) ($line['nameShort'] ?? ''));
        if ($routeName === '') {
            $routeName = trim((string) ($line['name'] ?? ''));
        }
        if ($routeName === '') {
            $routeName = match ($mode) {
                'FERRY', 'BOAT' => 'フェリー',
                default => '公共交通',
            };
        }

        $fromTime = (string) ($stops['departureTime'] ?? '');
        $toTime = (string) ($stops['arrivalTime'] ?? '');
        $board = TransitItinerary::timestamp($fromTime);
        $wait = ($board !== null && $previousArrival !== null) ? max(0, $board - $previousArrival) : 0;

        return [
            'type' => 'ride',
            'routeName' => $routeName,
            'mode' => strtolower((string) data_get($line, 'vehicle.type', 'transit')),
            'from' => (string) data_get($stops, 'departureStop.name', ''),
            'to' => (string) data_get($stops, 'arrivalStop.name', ''),
            'boardTime' => TransitItinerary::clockFrom($fromTime),
            'alightTime' => TransitItinerary::clockFrom($toTime),
            'waitSec' => $wait,
            'durationSec' => $seconds,
            'label' => $routeName,
        ];
    }

    /** @param array<string, mixed> $route */
    private function fareOf(array $route): int
    {
        $fare = data_get($route, 'travelAdvisory.transitFare');
        if (! is_array($fare)) {
            return 0;
        }
        if (isset($fare['units']) && is_numeric($fare['units'])) {
            return (int) $fare['units'];
        }

        return 0;
    }
}
