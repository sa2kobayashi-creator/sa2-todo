<?php

namespace App\Services\Transit;

use App\Services\EkispertConfigService;
use App\Services\IntegrationUsageService;
use App\Services\Transit\Raptor\ItineraryScorer;

/**
 * 駅すぱあとの経路検索を、画面共通の itinerary 形式に変換する。
 */
class EkispertRouteService
{
    public function __construct(
        private EkispertConfigService $ekispert,
        private IntegrationUsageService $usage,
    ) {}

    public function isReady(): bool
    {
        return $this->ekispert->isReady();
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

        $params = [
            'from' => $from,
            'to' => $to,
            'answerCount' => max(1, min(10, (int) ($query['limit'] ?? 5))),
            'sort' => $this->sortFor((string) ($query['preference'] ?? '')),
            'searchType' => ($query['timeType'] ?? 'departure') === 'arrival' ? 'arrival' : 'departure',
        ];
        $params += $this->timeParams((string) ($query['departureAt'] ?? ''));

        $result = $this->ekispert->get('search/course/extreme', $params);
        if (! $result['ok']) {
            return ['ok' => false, 'message' => $result['message'], 'itineraries' => []];
        }

        $this->usage->increment('ekispert');

        $courses = TransitItinerary::asList(data_get($result['data'], 'ResultSet.Course'));
        $itineraries = [];
        foreach ($courses as $course) {
            if (! is_array($course)) {
                continue;
            }
            $converted = $this->toItinerary($course);
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
            'engine' => '駅すぱあと',
            'preference' => (string) ($query['preference'] ?? ItineraryScorer::PREF_FASTEST),
            'fromStop' => ['id' => '', 'name' => $from],
            'toStop' => ['id' => '', 'name' => $to],
            'itineraries' => $itineraries,
        ];
    }

    private function sortFor(string $preference): string
    {
        return match ($preference) {
            ItineraryScorer::PREF_CHEAPEST => 'cheap',
            ItineraryScorer::PREF_FEWEST_TRANSFERS => 'transfer',
            default => 'time',
        };
    }

    /** @return array<string, string> */
    private function timeParams(string $departureAt): array
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/', trim($departureAt), $m)) {
            return [
                'date' => $m[1].$m[2].$m[3],
                'time' => $m[4].$m[5],
            ];
        }

        return [
            'date' => now()->format('Ymd'),
            'time' => now()->format('Hi'),
        ];
    }

    /** @param array<string, mixed> $course */
    private function toItinerary(array $course): ?array
    {
        $route = is_array($course['Route'] ?? null) ? $course['Route'] : [];
        $points = TransitItinerary::asList($route['Point'] ?? []);
        $lines = TransitItinerary::asList($route['Line'] ?? []);
        $legs = [];
        $waitSec = 0;
        $previousArrival = null;

        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                continue;
            }
            $fromPoint = is_array($points[$index] ?? null) ? $points[$index] : [];
            $toPoint = is_array($points[$index + 1] ?? null) ? $points[$index + 1] : [];
            $fromName = (string) data_get($fromPoint, 'Station.Name', data_get($fromPoint, 'Name', ''));
            $toName = (string) data_get($toPoint, 'Station.Name', data_get($toPoint, 'Name', ''));
            $fromTime = (string) data_get($line, 'DepartureState.Datetime.text', '');
            $toTime = (string) data_get($line, 'ArrivalState.Datetime.text', '');
            $type = strtolower((string) ($line['Type'] ?? ''));
            $board = TransitItinerary::timestamp($fromTime);
            $alight = TransitItinerary::timestamp($toTime);
            $seconds = ($board !== null && $alight !== null) ? max(0, $alight - $board) : ((int) ($line['timeOnBoard'] ?? 0) * 60);

            if ($type === 'walk' || $type === 'foot') {
                $legs[] = [
                    'type' => 'walk',
                    'from' => $fromName,
                    'to' => $toName,
                    'durationSec' => $seconds,
                    'label' => '徒歩',
                ];
                $previousArrival = $alight ?? $previousArrival;

                continue;
            }

            $routeName = trim((string) ($line['Name'] ?? ''));
            if ($routeName === '') {
                $routeName = trim((string) ($line['TypicalName'] ?? ''));
            }
            if ($routeName === '') {
                $routeName = '公共交通';
            }

            $wait = ($board !== null && $previousArrival !== null) ? max(0, $board - $previousArrival) : 0;
            $waitSec += $wait;

            $legs[] = [
                'type' => 'ride',
                'routeName' => $routeName,
                'mode' => $type !== '' ? $type : 'train',
                'from' => $fromName,
                'to' => $toName,
                'boardTime' => TransitItinerary::clockFrom($fromTime),
                'alightTime' => TransitItinerary::clockFrom($toTime),
                'waitSec' => $wait,
                'durationSec' => $seconds,
                'label' => $routeName,
            ];
            $previousArrival = $alight ?? $previousArrival;
        }

        $transfers = max(0, (int) ($route['transferCount'] ?? 0));

        return TransitItinerary::assemble($legs, $transfers, $this->fareOf($course), $waitSec);
    }

    /** @param array<string, mixed> $course */
    private function fareOf(array $course): int
    {
        foreach (TransitItinerary::asList($course['Price'] ?? []) as $price) {
            if (! is_array($price)) {
                continue;
            }
            $kind = (string) ($price['kind'] ?? '');
            if ($kind !== '' && $kind !== 'FareSummary' && $kind !== 'OnewaySummary') {
                continue;
            }
            if (isset($price['Oneway']) && is_numeric($price['Oneway'])) {
                return (int) $price['Oneway'];
            }
        }

        return 0;
    }
}
