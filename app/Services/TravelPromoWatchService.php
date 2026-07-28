<?php

namespace App\Services;

use App\Models\TravelAlert;
use App\Models\TravelProfile;
use App\Models\TravelPromo;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cebu Pacific は公開プロモコードより Seat Sale が本体。
 * 公式サイトのスクレイピングは避け、公開のまとめ記事から予約期間・渡航期間を取得する。
 */
class TravelPromoWatchService
{
    public const SOURCE_TPT = [
        'key' => 'tpt',
        'name' => 'The Poor Traveler',
        'url' => 'https://www.thepoortraveler.net/cebu-pacific-promo/?nowprocket=1',
        'display_url' => 'https://www.thepoortraveler.net/cebu-pacific-promo/',
    ];

    /**
     * @return array{fetched: int, created: int, updated: int, alerts: int, errors: list<string>, sales: list<array<string, mixed>>}
     */
    public function fetchAndSyncForUser(int $userId): array
    {
        $result = [
            'fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'alerts' => 0,
            'errors' => [],
            'sales' => [],
        ];

        try {
            $sales = $this->fetchFromPoorTraveler();
        } catch (\Throwable $e) {
            Log::warning('travel.promo_fetch_failed', [
                'source' => self::SOURCE_TPT['key'],
                'message' => $e->getMessage(),
            ]);
            $result['errors'][] = __('プロモ情報の取得に失敗しました: :msg', ['msg' => $e->getMessage()]);

            return $result;
        }

        $result['fetched'] = count($sales);
        $result['sales'] = $sales;
        $now = now();

        foreach ($sales as $sale) {
            $existing = TravelPromo::query()
                ->where('user_id', $userId)
                ->where('external_key', $sale['external_key'])
                ->first();

            $status = $this->statusForBookingWindow($sale['valid_from'], $sale['valid_until']);
            $payload = [
                'code' => $sale['code'],
                'title' => $sale['title'],
                'source_url' => $sale['source_url'],
                'applies_to' => $sale['applies_to'],
                'status' => $existing && in_array($existing->status, ['used', 'invalid'], true)
                    ? $existing->status
                    : $status,
                'valid_from' => $sale['valid_from'],
                'valid_until' => $sale['valid_until'],
                'travel_from' => $sale['travel_from'],
                'travel_until' => $sale['travel_until'],
                'notes' => $sale['notes'],
                'auto_fetched' => true,
                'last_seen_at' => $now,
            ];

            if ($existing) {
                $existing->fill($payload);
                $existing->save();
                $result['updated']++;
            } else {
                TravelPromo::query()->create(array_merge($payload, [
                    'user_id' => $userId,
                    'external_key' => $sale['external_key'],
                ]));
                $result['created']++;
                $result['alerts'] += $this->createNewSaleAlert($userId, $sale);
            }
        }

        return $result;
    }

    /**
     * @return array{users: int, created: int, updated: int, alerts: int, errors: int}
     */
    public function fetchAndSyncForAllUsers(): array
    {
        $stats = ['users' => 0, 'created' => 0, 'updated' => 0, 'alerts' => 0, 'errors' => 0];

        TravelProfile::query()
            ->orderBy('id')
            ->each(function (TravelProfile $profile) use (&$stats) {
                $stats['users']++;
                $result = $this->fetchAndSyncForUser((int) $profile->user_id);
                $stats['created'] += $result['created'];
                $stats['updated'] += $result['updated'];
                $stats['alerts'] += $result['alerts'];
                if ($result['errors'] !== []) {
                    $stats['errors']++;
                }
            });

        return $stats;
    }

    /**
     * @return list<array{
     *   external_key: string,
     *   code: string,
     *   title: string,
     *   source_url: string,
     *   applies_to: string,
     *   valid_from: string|null,
     *   valid_until: string|null,
     *   travel_from: string|null,
     *   travel_until: string|null,
     *   notes: string
     * }>
     */
    public function fetchFromPoorTraveler(): array
    {
        $response = Http::timeout(25)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; Sa2TravelWatch/1.0; +local)',
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'en-US,en;q=0.9,ja;q=0.8',
            ])
            ->get(self::SOURCE_TPT['url']);

        if (! $response->successful()) {
            throw new \RuntimeException('HTTP '.$response->status());
        }

        $html = (string) $response->body();
        if (! preg_match('/<article[\s\S]*?<\/article>/i', $html, $articleMatch)) {
            throw new \RuntimeException('article body not found');
        }

        $chunk = preg_replace('/<script[\s\S]*?<\/script>/i', '', $articleMatch[0]) ?? $articleMatch[0];
        $text = html_entity_decode(strip_tags($chunk), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        if (! preg_match_all(
            '/Promo Period:\s*(.+?)\s+Travel Period:\s*(.+?)(?=\s*(?:Please note|Promo Period:|If you have|TO BOOK|Which destinations|$))/iu',
            $text,
            $matches,
            PREG_SET_ORDER
        )) {
            throw new \RuntimeException('promo periods not found');
        }

        $sales = [];
        foreach ($matches as $index => $row) {
            $bookRaw = trim($row[1]);
            $travelRaw = trim($row[2]);
            $book = $this->parseMonthDayRange($bookRaw);
            $travel = $this->parseDayMonthRange($travelRaw);

            $title = $this->guessSaleTitle($text, $index, $bookRaw);
            $code = $this->makeCode($title, $book['from'] ?? null);
            $externalKey = 'tpt:'.substr(sha1(implode('|', [
                $book['from'] ?? '',
                $book['until'] ?? '',
                $travel['from'] ?? '',
                $travel['until'] ?? '',
                Str::lower($title),
            ])), 0, 24);

            $notes = __('予約期間: :book / 渡航期間: :travel', [
                'book' => $bookRaw,
                'travel' => $travelRaw,
            ]);
            $notes .= "\n".__('※掲載はベース運賃。税・手数料別。FUK⇔MNL 対象かは公式で要確認。');
            $notes .= "\n".__('取得元: :name', ['name' => self::SOURCE_TPT['name']]);

            $sales[] = [
                'external_key' => $externalKey,
                'code' => $code,
                'title' => $title,
                'source_url' => self::SOURCE_TPT['display_url'],
                'applies_to' => 'unknown',
                'valid_from' => $book['from'],
                'valid_until' => $book['until'],
                'travel_from' => $travel['from'],
                'travel_until' => $travel['until'],
                'notes' => $notes,
            ];
        }

        return $sales;
    }

    /**
     * @return array{from: ?string, until: ?string}
     */
    private function parseMonthDayRange(string $raw): array
    {
        // March 5-11, 2026
        if (preg_match('/([A-Za-z]+)\s+(\d{1,2})\s*[-–]\s*(\d{1,2}),?\s*(\d{4})/u', $raw, $m)) {
            return [
                'from' => $this->safeDate("{$m[1]} {$m[2]} {$m[4]}"),
                'until' => $this->safeDate("{$m[1]} {$m[3]} {$m[4]}"),
            ];
        }

        // March 5 to 11, 2026 / March 5 up to 11, 2026
        if (preg_match('/([A-Za-z]+)\s+(\d{1,2})\s+(?:to|up to|until)\s+(\d{1,2}),?\s*(\d{4})/iu', $raw, $m)) {
            return [
                'from' => $this->safeDate("{$m[1]} {$m[2]} {$m[4]}"),
                'until' => $this->safeDate("{$m[1]} {$m[3]} {$m[4]}"),
            ];
        }

        // March 5, 2026 to March 11, 2026
        if (preg_match('/([A-Za-z]+\s+\d{1,2},?\s*\d{4})\s+(?:to|until|-|–)\s+([A-Za-z]+\s+\d{1,2},?\s*\d{4})/iu', $raw, $m)) {
            return [
                'from' => $this->safeDate($m[1]),
                'until' => $this->safeDate($m[2]),
            ];
        }

        return ['from' => null, 'until' => null];
    }

    /**
     * @return array{from: ?string, until: ?string}
     */
    private function parseDayMonthRange(string $raw): array
    {
        // 1 November 2026 to 31 March 2027
        if (preg_match('/(\d{1,2}\s+[A-Za-z]+\s+\d{4})\s+(?:to|until|-|–)\s+(\d{1,2}\s+[A-Za-z]+\s+\d{4})/iu', $raw, $m)) {
            return [
                'from' => $this->safeDate($m[1]),
                'until' => $this->safeDate($m[2]),
            ];
        }

        // November 1, 2026 – March 31, 2027
        if (preg_match('/([A-Za-z]+\s+\d{1,2},?\s*\d{4})\s+(?:to|until|-|–)\s+([A-Za-z]+\s+\d{1,2},?\s*\d{4})/iu', $raw, $m)) {
            return [
                'from' => $this->safeDate($m[1]),
                'until' => $this->safeDate($m[2]),
            ];
        }

        return ['from' => null, 'until' => null];
    }

    private function safeDate(string $raw): ?string
    {
        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function guessSaleTitle(string $fullText, int $index, string $bookRaw): string
    {
        if ($index === 0 && preg_match('/\bPISO\b/i', $fullText)) {
            return 'Cebu Pacific PISO / Seat Sale';
        }
        if (preg_match('/\bP30\s*SALE\b/i', $fullText) && ($index > 0 || ! preg_match('/\bPISO\b/i', $fullText))) {
            return 'Cebu Pacific P30 SALE';
        }
        if (preg_match('/\bSEAT\s*SALE\b/i', $fullText)) {
            return 'Cebu Pacific Seat Sale';
        }

        return 'Cebu Pacific Promo ('.$bookRaw.')';
    }

    private function makeCode(string $title, ?string $from): string
    {
        $prefix = preg_match('/piso/i', $title) ? 'PISO'
            : (preg_match('/p30/i', $title) ? 'P30' : 'SALE');
        $date = $from ? str_replace('-', '', $from) : date('Ymd');

        return mb_substr($prefix.'-'.$date, 0, 64);
    }

    private function statusForBookingWindow(?string $from, ?string $until): string
    {
        $tz = config('app.timezone', 'Asia/Tokyo');
        $today = Carbon::now($tz)->startOfDay();

        if ($until) {
            $untilDay = Carbon::parse($until, $tz)->startOfDay();
            if ($today->gt($untilDay)) {
                return 'expired';
            }
        }
        if ($from) {
            $fromDay = Carbon::parse($from, $tz)->startOfDay();
            if ($today->lt($fromDay)) {
                return 'watching';
            }
        }

        return 'usable';
    }

    /**
     * @param  array<string, mixed>  $sale
     */
    private function createNewSaleAlert(int $userId, array $sale): int
    {
        $dedupe = 'promo:new:'.$sale['external_key'];
        $exists = TravelAlert::query()
            ->where('user_id', $userId)
            ->where('dedupe_key', $dedupe)
            ->exists();
        if ($exists) {
            return 0;
        }

        $body = trim(implode("\n", array_filter([
            __('予約期間: :a 〜 :b', [
                'a' => $sale['valid_from'] ?? '—',
                'b' => $sale['valid_until'] ?? '—',
            ]),
            __('渡航期間: :a 〜 :b', [
                'a' => $sale['travel_from'] ?? '—',
                'b' => $sale['travel_until'] ?? '—',
            ]),
            __('公式サイトで FUK⇔MNL の空席・税込を確認してください。'),
        ])));

        TravelAlert::query()->create([
            'user_id' => $userId,
            'type' => 'promo',
            'severity' => 'warn',
            'title' => __('新しい Seat Sale: :title', ['title' => $sale['title']]),
            'body' => $body,
            'dedupe_key' => $dedupe,
        ]);

        return 1;
    }
}
