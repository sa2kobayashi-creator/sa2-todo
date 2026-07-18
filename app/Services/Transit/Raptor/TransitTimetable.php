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

    /** @return array<string, list<string>> stopId => aliases */
    public function aliases(): array
    {
        return $this->payload['aliases'] ?? [
            'hakata' => ['博多駅', 'jr博多駅', 'jr博多', 'はかた', 'hakata'],
            'hakata_bc' => ['博多バスセンター', '博多bc'],
            'tenjin' => ['天神駅', '地下鉄天神', 'てんじん'],
            'tenjin_bc' => ['天神バスセンター', '天神bc'],
            'nishitetsu_fukuoka' => ['西鉄福岡天神', '西鉄福岡駅', '西鉄天神', '福岡（天神）'],
            'tenjin_minami' => ['天神南駅'],
            'airport' => ['福岡空港駅', '福岡空港国内線', 'ふくおかくうこう'],
            'meinohama' => ['姪浜駅', 'めいのはま'],
            'ohashi' => ['大橋駅', '西鉄大橋'],
            'yakuin' => ['薬院駅'],
            'fujisaki' => ['藤崎駅'],
            'nishijin' => ['西新駅'],
            'shikanoshima' => ['志賀島渡船場', 'しがのしま', '志賀島港'],
            'saitozaki' => ['西戸崎駅', '西戸崎'],
        ];
    }

    /**
     * Google 住所や「博多駅」表記からマッチ用トークンを作る
     *
     * @return list<string>
     */
    public function queryCandidates(string $query): array
    {
        $raw = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        if ($raw === '') {
            return [];
        }

        $candidates = [$raw];
        $stripped = preg_replace('/^日本[、,\s]*/u', '', $raw) ?? $raw;
        $stripped = preg_replace('/^(福岡県|福岡市)[、,\s]*/u', '', $stripped) ?? $stripped;
        $candidates[] = $stripped;

        // 「…区」以降
        if (preg_match('/区\s*(.+)$/u', $stripped, $m)) {
            $candidates[] = trim($m[1]);
        }

        // 末尾トークン（読点・空白区切り）
        foreach (preg_split('/[、,，\s]+/u', $stripped) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '') {
                $candidates[] = $part;
            }
        }

        // 「○○駅」「○○バス停」を抽出
        if (preg_match_all('/([一-龥ぁ-んァ-ヶA-Za-z0-9（）()]+?)(?:駅|バス停|バスセンター|渡船場|港)/u', $raw, $matches)) {
            foreach ($matches[0] as $full) {
                $candidates[] = $full;
            }
            foreach ($matches[1] as $base) {
                $candidates[] = $base;
            }
        }

        // 末尾が駅なら本体も
        foreach ($candidates as $c) {
            if (preg_match('/^(.+)駅$/u', $c, $m)) {
                $candidates[] = $m[1];
            }
            if (preg_match('/^(.+)バス停$/u', $c, $m)) {
                $candidates[] = $m[1];
            }
        }

        $normalized = [];
        foreach ($candidates as $c) {
            $c = trim(mb_strtolower($c));
            $c = str_replace(['（', '）', '(', ')', '・', ' ', '　'], '', $c);
            if ($c === '' || mb_strlen($c) < 2) {
                continue;
            }
            // 番地・丁目だけの弱いトークンは除外
            if (preg_match('/^[0-9０-９\-−ー丁目番地号]+$/u', $c)) {
                continue;
            }
            $normalized[$c] = true;
        }

        return array_keys($normalized);
    }

    /** @return list<array{id: string, name: string, lat: float, lon: float, score: float}> */
    public function findStopsByName(string $query, int $limit = 8): array
    {
        $candidates = $this->queryCandidates($query);
        if ($candidates === []) {
            return [];
        }

        $aliases = $this->aliases();
        $hits = [];

        foreach ($this->stops() as $stop) {
            $id = (string) $stop['id'];
            $name = (string) ($stop['name'] ?? '');
            $nameKey = str_replace(['（', '）', '(', ')', '・', ' ', '　'], '', mb_strtolower($name));
            $aliasList = array_map(
                fn ($a) => str_replace(['（', '）', '(', ')', '・', ' ', '　'], '', mb_strtolower((string) $a)),
                $aliases[$id] ?? []
            );

            $best = 0.0;
            foreach ($candidates as $c) {
                if ($nameKey === $c || in_array($c, $aliasList, true)) {
                    $best = max($best, 100.0);
                    continue;
                }
                // クエリ側に駅名が含まれる（住所フル対応）
                if (str_contains($c, $nameKey) && mb_strlen($nameKey) >= 2) {
                    $best = max($best, 90.0 + min(9, mb_strlen($nameKey)));
                }
                foreach ($aliasList as $alias) {
                    if ($alias !== '' && str_contains($c, $alias)) {
                        $best = max($best, 88.0 + min(9, mb_strlen($alias)));
                    }
                }
                // 駅名がクエリで始まる / クエリが駅名で始まる
                if (str_starts_with($nameKey, $c) || str_starts_with($c, $nameKey)) {
                    $best = max($best, 70.0);
                }
            }

            if ($best <= 0) {
                continue;
            }

            $hits[] = [
                'id' => $id,
                'name' => $name,
                'lat' => (float) $stop['lat'],
                'lon' => (float) $stop['lon'],
                'score' => $best,
            ];
        }

        usort($hits, fn ($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['name'], $b['name']));

        return array_slice($hits, 0, $limit);
    }

    public function resolveStopId(string $query): ?string
    {
        $hits = $this->findStopsByName($query, 1);
        if ($hits === []) {
            return null;
        }
        // 低スコアの偶然一致は採用しない
        if (($hits[0]['score'] ?? 0) < 70) {
            return null;
        }

        return $hits[0]['id'];
    }
}
