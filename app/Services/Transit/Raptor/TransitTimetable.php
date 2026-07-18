<?php

namespace App\Services\Transit\Raptor;

/**
 * 福岡特化の静的時刻表インデックス（RAPTOR 用）。
 * GTFS 相当の配列をメモリ上に持つ。
 */
class TransitTimetable
{
    /** @param array<string, mixed> $payload */
    public function __construct(private array $payload) {}

    public static function loadDefault(): self
    {
        $path = database_path('seed-data/transit/fukuoka_network.json');
        if (! is_file($path)) {
            $builder = new FukuokaNetworkBuilder;
            $builder->write($path);
        }

        $json = file_get_contents($path);
        $data = json_decode((string) $json, true);
        if (! is_array($data)) {
            throw new \RuntimeException('路線ネットワークの読み込みに失敗しました');
        }

        return new self($data);
    }

    /** @return array<string, array{id: string, name: string, lat: float, lon: float}> */
    public function stops(): array
    {
        return $this->payload['stops'] ?? [];
    }

    /** @return array<string, array{id: string, name: string, agency: string, mode: string, color?: string}> */
    public function routes(): array
    {
        return $this->payload['routes'] ?? [];
    }

    /**
     * route_id => ordered stop_ids
     *
     * @return array<string, list<string>>
     */
    public function routeStopSequences(): array
    {
        return $this->payload['routeStopSequences'] ?? [];
    }

    /**
     * stop_id => list of route_ids
     *
     * @return array<string, list<string>>
     */
    public function routesByStop(): array
    {
        return $this->payload['routesByStop'] ?? [];
    }

    /**
     * stop_id => sorted departures
     * each: [tripId, routeId, depSec, arrSec, seq]
     *
     * @return array<string, list<array{0: string, 1: string, 2: int, 3: int, 4: int}>>
     */
    public function departuresByStop(): array
    {
        return $this->payload['departuresByStop'] ?? [];
    }

    /**
     * tripId => stop sequence events
     * each: [stopId, arrSec, depSec, seq]
     *
     * @return array<string, list<array{0: string, 1: int, 2: int, 3: int}>>
     */
    public function tripEvents(): array
    {
        return $this->payload['tripEvents'] ?? [];
    }

    /**
     * fromStop => list of [toStop, walkSeconds]
     *
     * @return array<string, list<array{0: string, 1: int}>>
     */
    public function transfers(): array
    {
        return $this->payload['transfers'] ?? [];
    }

    /** @return array<string, float> */
    public function faresByRoute(): array
    {
        return $this->payload['faresByRoute'] ?? [];
    }

    /** @return list<array{id: string, name: string, lat: float, lon: float, score: float}> */
    public function findStopsByName(string $query, int $limit = 8): array
    {
        $q = mb_strtolower(trim($query));
        if ($q === '') {
            return [];
        }

        $hits = [];
        foreach ($this->stops() as $stop) {
            $name = (string) ($stop['name'] ?? '');
            $nameLower = mb_strtolower($name);
            if ($nameLower === $q) {
                $score = 100.0;
            } elseif (str_starts_with($nameLower, $q)) {
                $score = 80.0;
            } elseif (str_contains($nameLower, $q)) {
                $score = 50.0;
            } else {
                continue;
            }
            $hits[] = [
                'id' => $stop['id'],
                'name' => $name,
                'lat' => (float) $stop['lat'],
                'lon' => (float) $stop['lon'],
                'score' => $score,
            ];
        }

        usort($hits, fn ($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['name'], $b['name']));

        return array_slice($hits, 0, $limit);
    }

    public function resolveStopId(string $query): ?string
    {
        $hits = $this->findStopsByName($query, 1);

        return $hits[0]['id'] ?? null;
    }
}
