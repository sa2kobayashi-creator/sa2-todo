<?php

namespace App\Services\Transit\Raptor;

/**
 * RAPTOR（Round-Based Public Transit Optimized Router）
 *
 * - 乗換に最低 2 分、最大待ち 10 分（既定）を適用
 * - 待ち時間・乗換回数を itinerary に記録し、ItineraryScorer で再ランク
 */
class RaptorRouter
{
    public function __construct(
        private TransitTimetable $timetable,
        private ItineraryScorer $scorer = new ItineraryScorer,
    ) {}

    /**
     * @param array{
     *   from: string,
     *   to: string,
     *   departureSec?: int,
     *   preference?: string,
     *   minTransferMin?: int,
     *   maxTransferWaitMin?: int,
     *   maxRounds?: int,
     *   preferNishitetsuBus?: bool,
     *   limit?: int
     * } $query
     * @return array{ok: bool, message?: string, fromStop?: array<string, mixed>, toStop?: array<string, mixed>, itineraries: list<array<string, mixed>>}
     */
    public function search(array $query): array
    {
        $fromName = trim((string) ($query['from'] ?? ''));
        $toName = trim((string) ($query['to'] ?? ''));
        if ($fromName === '' || $toName === '') {
            return ['ok' => false, 'message' => '出発地と到着地を入力してください', 'itineraries' => []];
        }

        $fromId = $this->timetable->resolveStopId($fromName);
        $toId = $this->timetable->resolveStopId($toName);
        if (! $fromId || ! $toId) {
            return [
                'ok' => false,
                'message' => '停留所が見つかりません。例: 天神、博多、姪浜、福岡空港、大橋',
                'itineraries' => [],
            ];
        }
        if ($fromId === $toId) {
            return ['ok' => false, 'message' => '出発と到着が同じです', 'itineraries' => []];
        }

        $departureSec = (int) ($query['departureSec'] ?? (int) date('H') * 3600 + (int) date('i') * 60);
        $minTransfer = max(0, min(30, (int) ($query['minTransferMin'] ?? 2))) * 60;
        $maxTransferWait = max($minTransfer / 60, min(60, (int) ($query['maxTransferWaitMin'] ?? 10))) * 60;
        $maxRounds = max(1, min(6, (int) ($query['maxRounds'] ?? 4)));
        $limit = max(1, min(10, (int) ($query['limit'] ?? 5)));
        $preference = $this->scorer->normalizePreference($query['preference'] ?? null);
        $preferNishitetsu = array_key_exists('preferNishitetsuBus', $query)
            ? (bool) $query['preferNishitetsuBus']
            : true;

        $raw = $this->runRaptor($fromId, $toId, $departureSec, $minTransfer, $maxTransferWait, $maxRounds);
        if ($preferNishitetsu) {
            $busOnly = $this->runRaptor(
                $fromId,
                $toId,
                $departureSec,
                $minTransfer,
                $maxTransferWait,
                $maxRounds,
                ['nishitetsu_bus']
            );
            foreach ($busOnly as $it) {
                $raw[] = $it;
            }
        }
        // 署名で重複除去
        $unique = [];
        foreach ($raw as $it) {
            $unique[$it['signature']] = $it;
        }
        $ranked = $this->scorer->rank(array_values($unique), $preference, $preferNishitetsu);
        $ranked = array_slice($ranked, 0, $limit);

        $stops = $this->timetable->stops();

        return [
            'ok' => true,
            'engine' => 'RAPTOR',
            'preference' => $preference,
            'preferNishitetsuBus' => $preferNishitetsu,
            'minTransferMin' => (int) ($minTransfer / 60),
            'maxTransferWaitMin' => (int) ($maxTransferWait / 60),
            'fromStop' => $stops[$fromId] ?? ['id' => $fromId, 'name' => $fromName],
            'toStop' => $stops[$toId] ?? ['id' => $toId, 'name' => $toName],
            'itineraries' => $ranked,
        ];
    }

    /**
     * @param list<string>|null $allowedAgencies null = すべて
     * @return list<array<string, mixed>>
     */
    private function runRaptor(
        string $source,
        string $target,
        int $departureSec,
        int $minTransferSec,
        int $maxTransferWaitSec,
        int $maxRounds,
        ?array $allowedAgencies = null,
    ): array {
        $inf = 10 ** 9;
        $bestArrival = [];
        $bestArrival[$source] = $departureSec;

        /** @var array<int, array<string, array<string, mixed>>> $parent */
        $parent = [];
        $marked = [$source => true];

        // ラウンド0の徒歩（出発直後の乗換）
        $this->relaxTransfers(
            $marked,
            $bestArrival,
            $parent,
            0,
            $minTransferSec,
            $inf,
            true
        );

        for ($round = 1; $round <= $maxRounds; $round++) {
            if ($marked === []) {
                break;
            }

            $routeQueue = [];
            foreach (array_keys($marked) as $stopId) {
                foreach ($this->timetable->routesByStop()[$stopId] ?? [] as $routeId) {
                    if ($allowedAgencies !== null) {
                        $agency = $this->timetable->routes()[$routeId]['agency'] ?? '';
                        if (! in_array($agency, $allowedAgencies, true)) {
                            continue;
                        }
                    }
                    $seqList = $this->timetable->routeStopSequences()[$routeId] ?? [];
                    $seq = array_search($stopId, $seqList, true);
                    if ($seq === false) {
                        continue;
                    }
                    if (! isset($routeQueue[$routeId]) || $seq < $routeQueue[$routeId]) {
                        $routeQueue[$routeId] = (int) $seq;
                    }
                }
            }

            $marked = [];
            $roundImproved = [];

            foreach ($routeQueue as $routeId => $boardSeq) {
                $sequence = $this->timetable->routeStopSequences()[$routeId] ?? [];
                if ($sequence === []) {
                    continue;
                }

                $currentTripId = null;
                $boardStopId = null;
                $boardTime = null;
                $boardSeqActual = null;

                for ($seq = $boardSeq; $seq < count($sequence); $seq++) {
                    $stopId = $sequence[$seq];

                    if ($currentTripId !== null) {
                        $event = $this->tripEventAt($currentTripId, $seq);
                        if ($event !== null) {
                            $arr = $event[1];
                            if ($arr < ($bestArrival[$stopId] ?? $inf)) {
                                $bestArrival[$stopId] = $arr;
                                $parent[$round][$stopId] = [
                                    'type' => 'ride',
                                    'tripId' => $currentTripId,
                                    'routeId' => $routeId,
                                    'fromStopId' => $boardStopId,
                                    'boardTime' => $boardTime,
                                    'alightTime' => $arr,
                                    'boardSeq' => $boardSeqActual,
                                    'alightSeq' => $seq,
                                    'prevStopId' => $boardStopId,
                                    'prevRound' => $round - 1,
                                ];
                                $marked[$stopId] = true;
                                $roundImproved[$stopId] = true;
                            }
                        }
                    }

                    $earliest = $bestArrival[$stopId] ?? null;
                    if ($earliest === null) {
                        continue;
                    }

                    // 乗換待ち: 到着+最低2分〜最大10分（出発地の初回乗車は長めに待つ）
                    $isOriginBoard = ($stopId === $source && $round === 1);
                    $earliestBoard = $earliest + ($isOriginBoard ? 0 : $minTransferSec);
                    $latestBoard = $earliest + ($isOriginBoard ? 45 * 60 : $maxTransferWaitSec);

                    $candidate = $this->earliestTrip($routeId, $stopId, $seq, $earliestBoard, $latestBoard);
                    if ($candidate === null) {
                        continue;
                    }

                    if ($currentTripId === null || $candidate['dep'] < ($boardTime ?? $inf)) {
                        $currentTripId = $candidate['tripId'];
                        $boardStopId = $stopId;
                        $boardTime = $candidate['dep'];
                        $boardSeqActual = $seq;
                    }
                }
            }

            $this->relaxTransfers(
                $marked,
                $bestArrival,
                $parent,
                $round,
                $minTransferSec,
                $inf,
                false
            );
        }

        return $this->reconstructItineraries($source, $target, $departureSec, $parent, $bestArrival, $maxRounds);
    }

    /**
     * @param array<string, bool> $marked
     * @param array<string, int> $bestArrival
     * @param array<int, array<string, array<string, mixed>>> $parent
     */
    private function relaxTransfers(
        array &$marked,
        array &$bestArrival,
        array &$parent,
        int $round,
        int $minTransferSec,
        int $inf,
        bool $fromSource,
    ): void {
        $queue = array_keys($marked);
        for ($i = 0; $i < count($queue); $i++) {
            $fromId = $queue[$i];
            $base = $bestArrival[$fromId] ?? null;
            if ($base === null) {
                continue;
            }
            foreach ($this->timetable->transfers()[$fromId] ?? [] as [$toId, $walkSec]) {
                $arr = $base + (int) $walkSec + ($fromSource ? 0 : 0);
                // 徒歩連絡後も最低乗換バッファは次の乗車側で見る
                if ($arr < ($bestArrival[$toId] ?? $inf)) {
                    $bestArrival[$toId] = $arr;
                    $parent[$round][$toId] = [
                        'type' => 'walk',
                        'fromStopId' => $fromId,
                        'walkSec' => (int) $walkSec,
                        'alightTime' => $arr,
                        'prevStopId' => $fromId,
                        'prevRound' => $round,
                    ];
                    if (! isset($marked[$toId])) {
                        $marked[$toId] = true;
                        $queue[] = $toId;
                    }
                }
            }
        }
    }

    /**
     * @return array{tripId: string, dep: int}|null
     */
    private function earliestTrip(string $routeId, string $stopId, int $seq, int $earliestBoard, int $latestBoard): ?array
    {
        $best = null;
        foreach ($this->timetable->departuresByStop()[$stopId] ?? [] as $row) {
            [$tripId, $rowRouteId, $dep, $arr, $rowSeq] = $row;
            if ($rowRouteId !== $routeId || (int) $rowSeq !== $seq) {
                continue;
            }
            if ($dep < $earliestBoard) {
                continue;
            }
            if ($dep > $latestBoard) {
                break;
            }
            $best = ['tripId' => $tripId, 'dep' => (int) $dep];
            break; // departures are sorted
        }

        return $best;
    }

    /** @return array{0: string, 1: int, 2: int, 3: int}|null */
    private function tripEventAt(string $tripId, int $seq): ?array
    {
        foreach ($this->timetable->tripEvents()[$tripId] ?? [] as $event) {
            if ((int) $event[3] === $seq) {
                return $event;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, array<string, mixed>>> $parent
     * @param array<string, int> $bestArrival
     * @return list<array<string, mixed>>
     */
    private function reconstructItineraries(
        string $source,
        string $target,
        int $departureSec,
        array $parent,
        array $bestArrival,
        int $maxRounds,
    ): array {
        if (! isset($bestArrival[$target])) {
            return [];
        }

        $itineraries = [];
        for ($round = 1; $round <= $maxRounds; $round++) {
            if (! isset($parent[$round][$target]) && $round < $maxRounds) {
                // target may have been reached earlier and improved only by walk in later rounds
            }
            $path = $this->buildPath($source, $target, $round, $parent);
            if ($path === null) {
                continue;
            }
            $it = $this->pathToItinerary($path, $departureSec, $bestArrival[$target]);
            if ($it !== null) {
                $key = $it['signature'];
                if (! isset($itineraries[$key]) || $it['durationSec'] < $itineraries[$key]['durationSec']) {
                    $itineraries[$key] = $it;
                }
            }
        }

        // 最終到達から逆に最も良い parent チェーンも追加
        $bestPath = $this->buildBestPath($source, $target, $parent, $maxRounds);
        if ($bestPath !== null) {
            $it = $this->pathToItinerary($bestPath, $departureSec, $bestArrival[$target]);
            if ($it !== null) {
                $itineraries[$it['signature']] = $it;
            }
        }

        return array_values($itineraries);
    }

    /**
     * @param array<int, array<string, array<string, mixed>>> $parent
     * @return list<array<string, mixed>>|null
     */
    private function buildPath(string $source, string $target, int $round, array $parent): ?array
    {
        if (! isset($parent[$round][$target])) {
            return $this->buildBestPath($source, $target, $parent, $round);
        }

        $legs = [];
        $stop = $target;
        $r = $round;
        $guard = 0;
        while ($stop !== $source && $guard++ < 40) {
            $node = $parent[$r][$stop] ?? null;
            if ($node === null) {
                // 早いラウンドを探す
                $found = false;
                for ($rr = $r; $rr >= 0; $rr--) {
                    if (isset($parent[$rr][$stop])) {
                        $node = $parent[$rr][$stop];
                        $r = $rr;
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    return null;
                }
            }
            $legs[] = $node + ['toStopId' => $stop];
            $prev = $node['fromStopId'] ?? $node['prevStopId'] ?? null;
            if ($prev === null) {
                return null;
            }
            $stop = $prev;
            if (($node['type'] ?? '') === 'ride') {
                $r = max(0, $r - 1);
            }
        }

        if ($stop !== $source) {
            return null;
        }

        return array_reverse($legs);
    }

    /**
     * @param array<int, array<string, array<string, mixed>>> $parent
     * @return list<array<string, mixed>>|null
     */
    private function buildBestPath(string $source, string $target, array $parent, int $maxRounds): ?array
    {
        for ($round = $maxRounds; $round >= 0; $round--) {
            if (isset($parent[$round][$target])) {
                return $this->buildPath($source, $target, $round, $parent);
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $legs
     * @return array<string, mixed>|null
     */
    private function pathToItinerary(array $legs, int $departureSec, int $arrivalSec): ?array
    {
        if ($legs === []) {
            return null;
        }

        $stops = $this->timetable->stops();
        $routes = $this->timetable->routes();
        $fares = $this->timetable->faresByRoute();

        $displayLegs = [];
        $waitSec = 0;
        $walkSec = 0;
        $rideSec = 0;
        $transfers = 0;
        $fare = 0.0;
        $usesNishitetsuBus = false;
        $prevAlight = $departureSec;
        $routeIds = [];

        foreach ($legs as $leg) {
            $type = $leg['type'] ?? '';
            if ($type === 'walk') {
                $from = $stops[$leg['fromStopId']]['name'] ?? $leg['fromStopId'];
                $to = $stops[$leg['toStopId']]['name'] ?? $leg['toStopId'];
                $ws = (int) ($leg['walkSec'] ?? 0);
                $walkSec += $ws;
                $displayLegs[] = [
                    'type' => 'walk',
                    'from' => $from,
                    'to' => $to,
                    'durationSec' => $ws,
                    'label' => '徒歩連絡',
                ];
                $prevAlight = (int) ($leg['alightTime'] ?? $prevAlight + $ws);
                continue;
            }

            if ($type === 'ride') {
                $routeId = (string) ($leg['routeId'] ?? '');
                $route = $routes[$routeId] ?? [];
                $board = (int) ($leg['boardTime'] ?? 0);
                $alight = (int) ($leg['alightTime'] ?? 0);
                $w = max(0, $board - $prevAlight);
                $waitSec += $w;
                $rideSec += max(0, $alight - $board);
                $routeIds[] = $routeId;
                if (($route['agency'] ?? '') === 'nishitetsu_bus') {
                    $usesNishitetsuBus = true;
                }
                $fare += (float) ($fares[$routeId] ?? $route['fare'] ?? 0);
                $displayLegs[] = [
                    'type' => 'ride',
                    'routeId' => $routeId,
                    'routeName' => $route['name'] ?? $routeId,
                    'agency' => $route['agency'] ?? '',
                    'mode' => $route['mode'] ?? '',
                    'from' => $stops[$leg['fromStopId']]['name'] ?? $leg['fromStopId'],
                    'to' => $stops[$leg['toStopId']]['name'] ?? $leg['toStopId'],
                    'boardTime' => $this->formatClock($board),
                    'alightTime' => $this->formatClock($alight),
                    'waitSec' => $w,
                    'durationSec' => max(0, $alight - $board),
                    'label' => $route['name'] ?? $routeId,
                ];
                $prevAlight = $alight;
            }
        }

        $rideLegs = array_values(array_filter($displayLegs, fn ($l) => ($l['type'] ?? '') === 'ride'));
        $transfers = max(0, count($rideLegs) - 1);

        $duration = max(0, $arrivalSec - $departureSec);
        $signature = implode('|', array_map(function ($l) {
            if (($l['type'] ?? '') === 'ride') {
                return 'R:'.($l['routeId'] ?? '').':'.($l['from'] ?? '').'>'.($l['to'] ?? '').':'.($l['boardTime'] ?? '');
            }

            return 'W:'.($l['from'] ?? '').'>'.($l['to'] ?? '');
        }, $displayLegs));

        return [
            'departureTime' => $this->formatClock($departureSec),
            'arrivalTime' => $this->formatClock($arrivalSec),
            'durationSec' => $duration,
            'durationLabel' => $this->formatDuration($duration),
            'waitSec' => $waitSec,
            'waitLabel' => $this->formatDuration($waitSec),
            'walkSec' => $walkSec,
            'rideSec' => $rideSec,
            'transfers' => $transfers,
            'fare' => round($fare),
            'fareLabel' => '¥'.number_format((int) round($fare)),
            'usesNishitetsuBus' => $usesNishitetsuBus,
            'legs' => $displayLegs,
            'summary' => $this->summaryLabel($displayLegs, $transfers),
            'signature' => $signature,
        ];
    }

    /** @param list<array<string, mixed>> $legs */
    private function summaryLabel(array $legs, int $transfers): string
    {
        $names = [];
        foreach ($legs as $leg) {
            if (($leg['type'] ?? '') === 'ride') {
                $names[] = $leg['routeName'] ?? '';
            }
        }
        $names = array_values(array_unique(array_filter($names)));
        $base = $names !== [] ? implode(' → ', $names) : '経路';

        return $transfers > 0 ? $base."（乗換{$transfers}回）" : $base;
    }

    private function formatClock(int $sec): string
    {
        $sec = $sec % (24 * 3600);
        if ($sec < 0) {
            $sec += 24 * 3600;
        }
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);

        return sprintf('%02d:%02d', $h, $m);
    }

    private function formatDuration(int $sec): string
    {
        $sec = max(0, $sec);
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        if ($h > 0) {
            return $h.'時間'.$m.'分';
        }

        return $m.'分';
    }
}
