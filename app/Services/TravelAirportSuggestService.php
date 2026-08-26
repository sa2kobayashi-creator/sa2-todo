<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TravelAirportSuggestService
{
    /** @var array<string, string> */
    private const ALIASES = [
        '福岡' => 'FUK', 'ふくおか' => 'FUK', 'fukuoka' => 'FUK',
        '羽田' => 'HND', 'はねだ' => 'HND', 'haneda' => 'HND', 'tokyo haneda' => 'HND',
        '成田' => 'NRT', 'なりた' => 'NRT', 'narita' => 'NRT',
        '東京' => 'TYO', 'とうきょう' => 'TYO', 'tokyo' => 'TYO',
        '関空' => 'KIX', '関西' => 'KIX', 'kansai' => 'KIX', 'kix' => 'KIX',
        '伊丹' => 'ITM', '大阪' => 'OSA', 'osaka' => 'OSA',
        '中部' => 'NGO', '名古屋' => 'NGO', 'nagoya' => 'NGO',
        '新千歳' => 'CTS', '千歳' => 'CTS', '札幌' => 'SPK', 'sapporo' => 'SPK',
        '那覇' => 'OKA', '沖縄' => 'OKA', 'okinawa' => 'OKA', 'naha' => 'OKA',
        '福岡空港' => 'FUK', '羽田空港' => 'HND', '成田空港' => 'NRT',
        'マニラ' => 'MNL', 'まにら' => 'MNL', 'manila' => 'MNL',
        'セブ' => 'CEB', 'cebu' => 'CEB',
        'バンコク' => 'BKK', 'bangkok' => 'BKK',
        'ソウル' => 'ICN', '仁川' => 'ICN', 'seoul' => 'SEL', 'incheon' => 'ICN',
        '台北' => 'TPE', 'taipei' => 'TPE',
        'シンガポール' => 'SIN', 'singapore' => 'SIN',
        '香港' => 'HKG', 'hong kong' => 'HKG', 'hongkong' => 'HKG',
        '上海' => 'SHA', 'shanghai' => 'SHA',
        '北京' => 'BJS', 'beijing' => 'BJS',
        'ロサンゼルス' => 'LAX', 'los angeles' => 'LAX',
        'ホノルル' => 'HNL', 'honolulu' => 'HNL',
        'パリ' => 'PAR', 'paris' => 'PAR',
        'ロンドン' => 'LON', 'london' => 'LON',
    ];

    /**
     * @return list<array{code: string, name: string, country: string, type: string, label: string}>
     */
    public function suggest(string $term, string $locale = 'ja'): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 1) {
            return [];
        }

        $locale = $locale === 'en' ? 'en' : 'ja';
        $cacheKey = 'travel.airport.suggest.'.$locale.'.'.md5(mb_strtolower($term));

        return Cache::remember($cacheKey, 6 * 3600, function () use ($term, $locale) {
            $local = $this->localHits($term);
            $remote = $this->remoteHits($term, $locale);

            $merged = [];
            $seen = [];
            foreach (array_merge($local, $remote) as $row) {
                $code = strtoupper((string) ($row['code'] ?? ''));
                if ($code === '' || isset($seen[$code])) {
                    continue;
                }
                $seen[$code] = true;
                $merged[] = $row;
                if (count($merged) >= 8) {
                    break;
                }
            }

            return $merged;
        });
    }

    public function resolveCode(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }
        if (preg_match('/\(([A-Za-z]{3})\)/', $input, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match('/^[A-Za-z]{3}$/', $input)) {
            return strtoupper($input);
        }

        $alias = $this->aliasCode($input);
        if ($alias !== '') {
            return $alias;
        }

        $hits = $this->suggest($input);

        return strtoupper((string) ($hits[0]['code'] ?? ''));
    }

    /** @return list<array{code: string, name: string, country: string, type: string, label: string}> */
    private function localHits(string $term): array
    {
        $needle = mb_strtolower($term);
        $hits = [];
        if (preg_match('/^[A-Za-z]{3}$/', $term)) {
            $code = strtoupper($term);
            $hits[] = $this->hit($code, $code, '', 'code');
        }
        foreach (self::ALIASES as $name => $code) {
            if (mb_strpos(mb_strtolower((string) $name), $needle) !== false || mb_strtolower($code) === $needle) {
                $hits[] = $this->hit($code, (string) $name, '', 'alias');
            }
        }

        return $hits;
    }

    /** @return list<array{code: string, name: string, country: string, type: string, label: string}> */
    private function remoteHits(string $term, string $locale): array
    {
        try {
            $query = http_build_query([
                'term' => $term,
                'locale' => $locale,
            ]).'&types[]=city&types[]=airport';
            $response = Http::timeout(8)
                ->acceptJson()
                ->get('https://autocomplete.travelpayouts.com/places2?'.$query);
            if (! $response->successful()) {
                return [];
            }
            $rows = $response->json();
            if (! is_array($rows)) {
                return [];
            }

            $hits = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $code = strtoupper(trim((string) ($row['code'] ?? '')));
                if ($code === '') {
                    continue;
                }
                $name = trim((string) ($row['name'] ?? $code));
                $country = trim((string) ($row['country_name'] ?? ''));
                $type = (string) ($row['type'] ?? 'city');
                $hits[] = $this->hit($code, $name, $country, $type);
            }

            return $hits;
        } catch (\Throwable $e) {
            Log::info('travel.airport_suggest_failed', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /** @return array{code: string, name: string, country: string, type: string, label: string} */
    private function hit(string $code, string $name, string $country, string $type): array
    {
        $label = $name !== '' && strtoupper($name) !== $code
            ? $name.' ('.$code.')'
            : $code;
        if ($country !== '') {
            $label .= ' · '.$country;
        }

        return [
            'code' => $code,
            'name' => $name !== '' ? $name : $code,
            'country' => $country,
            'type' => $type,
            'label' => $label,
        ];
    }

    private function aliasCode(string $input): string
    {
        $key = mb_strtolower(trim($input));

        return self::ALIASES[$input] ?? self::ALIASES[$key] ?? '';
    }
}
