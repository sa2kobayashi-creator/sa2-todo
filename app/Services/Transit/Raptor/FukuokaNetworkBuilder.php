<?php

namespace App\Services\Transit\Raptor;

/**
 * 福岡都心の簡易ネットワークを生成（西鉄バスを厚めに配置）。
 * 正式な全線 GTFS ではないが、RAPTOR の乗換・待ち時間評価用に十分な密度を持つ。
 */
class FukuokaNetworkBuilder
{
    public function write(string $path): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, json_encode($this->build(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $stops = $this->stops();
        $routes = $this->routes();
        $sequences = $this->routeSequences();
        $departuresByStop = [];
        $tripEvents = [];
        $routesByStop = [];
        $faresByRoute = [];

        foreach ($sequences as $routeId => $stopIds) {
            foreach ($stopIds as $stopId) {
                $routesByStop[$stopId] ??= [];
                if (! in_array($routeId, $routesByStop[$stopId], true)) {
                    $routesByStop[$stopId][] = $routeId;
                }
            }
            $faresByRoute[$routeId] = $routes[$routeId]['fare'] ?? 220.0;
            $this->generateTrips(
                $routeId,
                $routes[$routeId],
                $stopIds,
                $departuresByStop,
                $tripEvents
            );
        }

        foreach ($departuresByStop as $stopId => $rows) {
            usort($rows, fn ($a, $b) => $a[2] <=> $b[2]);
            $departuresByStop[$stopId] = $rows;
        }

        return [
            'region' => 'fukuoka',
            'version' => '2026-07-18c',
            'note' => '福岡都心の簡易ダイヤ。西鉄バス路線を優先配置（志賀島線含む）。',
            'stops' => $stops,
            'routes' => $routes,
            'routeStopSequences' => $sequences,
            'routesByStop' => $routesByStop,
            'departuresByStop' => $departuresByStop,
            'tripEvents' => $tripEvents,
            'transfers' => $this->buildTransfers($stops),
            'faresByRoute' => $faresByRoute,
            'aliases' => [
                'hakata' => ['博多駅', 'jr博多駅', 'jr博多', 'はかた'],
                'hakata_bc' => ['博多バスセンター', '博多bc'],
                'tenjin' => ['天神駅', '地下鉄天神', 'てんじん'],
                'tenjin_bc' => ['天神バスセンター', '天神bc'],
                'nishitetsu_fukuoka' => ['西鉄福岡天神', '西鉄福岡駅', '西鉄天神'],
                'airport' => ['福岡空港駅', '福岡空港国内線'],
                'meinohama' => ['姪浜駅', 'めいのはま'],
                'ohashi' => ['大橋駅', '西鉄大橋'],
                'shikanoshima' => ['志賀島渡船場', 'しがのしま', '志賀島港'],
                'saitozaki' => ['西戸崎駅', '西戸崎'],
                'gannosu' => ['雁ノ巣', '雁の巣レクリエーションセンター'],
            ],
        ];
    }

    /** @return array<string, array{id: string, name: string, lat: float, lon: float}> */
    private function stops(): array
    {
        $raw = [
            'hakata' => ['博多', 33.5897, 130.4207],
            'hakata_bc' => ['博多バスセンター', 33.5904, 130.4201],
            'tenjin' => ['天神', 33.5902, 130.4017],
            'tenjin_bc' => ['天神バスセンター', 33.5910, 130.3998],
            'nishitetsu_fukuoka' => ['西鉄福岡（天神）', 33.5897, 130.3995],
            'tenjin_minami' => ['天神南', 33.5878, 130.4008],
            'airport' => ['福岡空港', 33.5973, 130.4490],
            'gion' => ['祇園', 33.5935, 130.4128],
            'nakasu_kawabata' => ['中洲川端', 33.5939, 130.4065],
            'akasakae' => ['赤坂', 33.5858, 130.3928],
            'oori' => ['大濠公園', 33.5850, 130.3795],
            'nishijin' => ['西新', 33.5848, 130.3605],
            'fujisaki' => ['藤崎', 33.5825, 130.3488],
            'muromi' => ['室見', 33.5818, 130.3395],
            'meinohama' => ['姪浜', 33.5835, 130.3248],
            'yakuin' => ['薬院', 33.5825, 130.4018],
            'takamiya' => ['高宮', 33.5698, 130.4145],
            'ohashi' => ['大橋', 33.5592, 130.4265],
            'ropponmatsu' => ['六本松', 33.5755, 130.3805],
            'hashimoto' => ['橋本', 33.5555, 130.3488],
            'kashii' => ['香椎', 33.6595, 130.4445],
            'chihaya' => ['千早', 33.6508, 130.4378],
            'hakozaki' => ['箱崎', 33.6178, 130.4248],
            'yoshizuka' => ['吉塚', 33.6065, 130.4235],
            'saitozaki' => ['西戸崎', 33.6502, 130.3615],
            'gannosu' => ['雁の巣', 33.6668, 130.4085],
            'shikanoshima' => ['志賀島', 33.6745, 130.3028],
        ];

        $stops = [];
        foreach ($raw as $id => [$name, $lat, $lon]) {
            $stops[$id] = [
                'id' => $id,
                'name' => $name,
                'lat' => $lat,
                'lon' => $lon,
            ];
        }

        return $stops;
    }

    /** @return array<string, array<string, mixed>> */
    private function routes(): array
    {
        return [
            'nn_bus_airport' => [
                'id' => 'nn_bus_airport',
                'name' => '西鉄バス 空港線',
                'agency' => 'nishitetsu_bus',
                'mode' => 'bus',
                'headway' => 10,
                'legMinutes' => 8,
                'fare' => 260,
                'priority' => 1.0,
            ],
            'nn_bus_tenjin_hakata' => [
                'id' => 'nn_bus_tenjin_hakata',
                'name' => '西鉄バス 天神〜博多',
                'agency' => 'nishitetsu_bus',
                'mode' => 'bus',
                'headway' => 6,
                'legMinutes' => 7,
                'fare' => 180,
                'priority' => 1.0,
            ],
            'nn_bus_west' => [
                'id' => 'nn_bus_west',
                'name' => '西鉄バス 西部線（天神〜姪浜）',
                'agency' => 'nishitetsu_bus',
                'mode' => 'bus',
                'headway' => 8,
                'legMinutes' => 6,
                'fare' => 230,
                'priority' => 1.0,
            ],
            'nn_bus_south' => [
                'id' => 'nn_bus_south',
                'name' => '西鉄バス 南部線（天神〜大橋）',
                'agency' => 'nishitetsu_bus',
                'mode' => 'bus',
                'headway' => 9,
                'legMinutes' => 7,
                'fare' => 220,
                'priority' => 1.0,
            ],
            'nn_bus_shikanoshima' => [
                'id' => 'nn_bus_shikanoshima',
                'name' => '西鉄バス 志賀島線',
                'agency' => 'nishitetsu_bus',
                'mode' => 'bus',
                'headway' => 25,
                'legMinutes' => 12,
                'fare' => 530,
                'priority' => 1.0,
            ],
            'nn_bus_east' => [
                'id' => 'nn_bus_east',
                'name' => '西鉄バス 東部線（香椎〜博多）',
                'agency' => 'nishitetsu_bus',
                'mode' => 'bus',
                'headway' => 12,
                'legMinutes' => 8,
                'fare' => 260,
                'priority' => 1.0,
            ],
            'nn_rail_tenjin_ohashi' => [
                'id' => 'nn_rail_tenjin_ohashi',
                'name' => '西鉄天神大牟田線',
                'agency' => 'nishitetsu_rail',
                'mode' => 'rail',
                'headway' => 8,
                'legMinutes' => 4,
                'fare' => 200,
                'priority' => 0.4,
            ],
            'subway_airport' => [
                'id' => 'subway_airport',
                'name' => '地下鉄空港線',
                'agency' => 'subway',
                'mode' => 'subway',
                'headway' => 5,
                'legMinutes' => 3,
                'fare' => 260,
                'priority' => 0.5,
            ],
            'subway_nanakuma' => [
                'id' => 'subway_nanakuma',
                'name' => '地下鉄七隈線',
                'agency' => 'subway',
                'mode' => 'subway',
                'headway' => 7,
                'legMinutes' => 4,
                'fare' => 210,
                'priority' => 0.5,
            ],
            'jr_kagoshima' => [
                'id' => 'jr_kagoshima',
                'name' => 'JR鹿児島本線',
                'agency' => 'jr',
                'mode' => 'rail',
                'headway' => 10,
                'legMinutes' => 4,
                'fare' => 190,
                'priority' => 0.3,
            ],
            'jr_kashi' => [
                'id' => 'jr_kashi',
                'name' => 'JR香椎線',
                'agency' => 'jr',
                'mode' => 'rail',
                'headway' => 20,
                'legMinutes' => 8,
                'fare' => 280,
                'priority' => 0.25,
            ],
            'ferry_shikanoshima' => [
                'id' => 'ferry_shikanoshima',
                'name' => '市営渡船 志賀島航路',
                'agency' => 'ferry',
                'mode' => 'ferry',
                'headway' => 40,
                'legMinutes' => 15,
                'fare' => 480,
                'priority' => 0.2,
            ],
        ];
    }

    /** @return array<string, list<string>> */
    private function routeSequences(): array
    {
        return [
            'nn_bus_airport' => ['tenjin_bc', 'nakasu_kawabata', 'gion', 'hakata_bc', 'airport'],
            'nn_bus_tenjin_hakata' => ['tenjin_bc', 'nakasu_kawabata', 'gion', 'hakata_bc', 'hakata'],
            'nn_bus_west' => ['tenjin_bc', 'akasakae', 'oori', 'nishijin', 'fujisaki', 'muromi', 'meinohama'],
            'nn_bus_south' => ['tenjin_bc', 'yakuin', 'takamiya', 'ohashi'],
            // 志賀島〜博多・天神（西鉄バス）
            'nn_bus_shikanoshima' => [
                'shikanoshima',
                'saitozaki',
                'gannosu',
                'kashii',
                'chihaya',
                'hakozaki',
                'yoshizuka',
                'hakata_bc',
                'tenjin_bc',
            ],
            // 東部エリアのバス連絡（渡船→JR香椎後の乗換用にも）
            'nn_bus_east' => ['kashii', 'chihaya', 'hakozaki', 'yoshizuka', 'hakata_bc', 'tenjin_bc'],
            'nn_rail_tenjin_ohashi' => ['nishitetsu_fukuoka', 'yakuin', 'takamiya', 'ohashi'],
            'subway_airport' => ['meinohama', 'muromi', 'fujisaki', 'nishijin', 'oori', 'akasakae', 'tenjin', 'nakasu_kawabata', 'gion', 'hakata', 'airport'],
            'subway_nanakuma' => ['hashimoto', 'ropponmatsu', 'yakuin', 'tenjin_minami', 'hakata'],
            'jr_kagoshima' => ['kashii', 'chihaya', 'hakozaki', 'yoshizuka', 'hakata'],
            // 香椎線は西戸崎〜香椎（博多直通は鹿児島本線乗換）
            'jr_kashi' => ['saitozaki', 'kashii'],
            'ferry_shikanoshima' => ['shikanoshima', 'saitozaki'],
        ];
    }

    /**
     * @param array<string, mixed> $route
     * @param list<string> $stopIds
     * @param array<string, list<array{0: string, 1: string, 2: int, 3: int, 4: int}>> $departuresByStop
     * @param array<string, list<array{0: string, 1: int, 2: int, 3: int}>> $tripEvents
     */
    private function generateTrips(
        string $routeId,
        array $route,
        array $stopIds,
        array &$departuresByStop,
        array &$tripEvents,
    ): void {
        $headway = max(4, (int) ($route['headway'] ?? 10)) * 60;
        $leg = max(2, (int) ($route['legMinutes'] ?? 5)) * 60;
        $start = 5 * 3600 + 30 * 60; // 05:30
        $end = 23 * 3600 + 30 * 60; // 23:30
        $tripIndex = 0;

        for ($dep0 = $start; $dep0 <= $end; $dep0 += $headway) {
            $tripIndex++;
            $tripId = $routeId.'_'.$tripIndex;
            $events = [];
            $t = $dep0;
            foreach ($stopIds as $seq => $stopId) {
                $arr = $t;
                $dep = $t;
                $events[] = [$stopId, $arr, $dep, $seq];
                $departuresByStop[$stopId][] = [$tripId, $routeId, $dep, $arr, $seq];
                $t += $leg;
            }
            $tripEvents[$tripId] = $events;

            // 復路
            $tripIndex++;
            $tripIdBack = $routeId.'_b'.$tripIndex;
            $eventsBack = [];
            $t = $dep0 + 120; // 2分ずらして復路
            $rev = array_reverse($stopIds);
            foreach ($rev as $seq => $stopId) {
                $arr = $t;
                $dep = $t;
                $eventsBack[] = [$stopId, $arr, $dep, $seq];
                $departuresByStop[$stopId][] = [$tripIdBack, $routeId, $dep, $arr, $seq];
                $t += $leg;
            }
            $tripEvents[$tripIdBack] = $eventsBack;
        }
    }

    /**
     * @param array<string, array{id: string, name: string, lat: float, lon: float}> $stops
     * @return array<string, list<array{0: string, 1: int}>>
     */
    private function buildTransfers(array $stops): array
    {
        $transfers = [];
        $ids = array_keys($stops);
        foreach ($ids as $i => $fromId) {
            foreach ($ids as $j => $toId) {
                if ($i === $j) {
                    continue;
                }
                $meters = $this->haversineMeters(
                    (float) $stops[$fromId]['lat'],
                    (float) $stops[$fromId]['lon'],
                    (float) $stops[$toId]['lat'],
                    (float) $stops[$toId]['lon'],
                );
                // 徒歩連絡はおおむね 450m 以内（福岡都心の乗換）
                if ($meters > 450) {
                    continue;
                }
                $walkSec = (int) max(90, round($meters / 1.2)); // 約 4.3km/h
                $transfers[$fromId][] = [$toId, $walkSec];
            }
        }

        // 同名・近接の明示リンク
        $forced = [
            ['tenjin', 'tenjin_bc', 120],
            ['tenjin', 'nishitetsu_fukuoka', 150],
            ['tenjin', 'tenjin_minami', 180],
            ['tenjin_bc', 'nishitetsu_fukuoka', 90],
            ['hakata', 'hakata_bc', 120],
        ];
        foreach ($forced as [$a, $b, $sec]) {
            $transfers[$a][] = [$b, $sec];
            $transfers[$b][] = [$a, $sec];
        }

        return $transfers;
    }

    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000;
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $h = sin($dLat / 2) ** 2 + cos($p1) * cos($p2) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($h)));
    }
}
