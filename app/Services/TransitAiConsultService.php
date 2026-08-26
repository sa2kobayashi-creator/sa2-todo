<?php

namespace App\Services;

use App\Models\TransitFavorite;
use App\Models\User;
use App\Services\Transit\Raptor\ItineraryScorer;
use App\Services\Transit\RouteSearchService;

/**
 * 路線検索の AI 相談。
 *
 * 会話だけに答えさせず、毎回「相談 → 経路 API → その結果で回答」と回す。
 * 時刻・乗換・運賃・路線名は API の itinerary だけを正とする。
 */
class TransitAiConsultService
{
    public function __construct(
        private CloudflareWorkersAiConfigService $workersAi,
        private RouteSearchService $routes,
        private IntegrationUsageService $usage,
    ) {}

    /**
     * @param  list<array{role?: mixed, content?: mixed}>  $history
     * @param  array<string, mixed>  $context  画面の検索欄・直前の検索条件
     * @return array{
     *   ok: bool,
     *   text: string,
     *   message: string,
     *   searched: bool,
     *   itineraries: list<array<string, mixed>>,
     *   search: array<string, mixed>|null
     * }
     */
    public function ask(User $user, string $prompt, array $history = [], array $context = []): array
    {
        $query = $this->resolveQuery($user, $prompt, $history, $context);
        $search = null;
        if ($query['from'] !== '' && $query['to'] !== '') {
            $search = $this->routes->search([
                'from' => $query['from'],
                'to' => $query['to'],
                'departureAt' => $query['departureAt'],
                'timeType' => $query['timeType'],
                'preference' => $query['preference'],
                'preferNishitetsuBus' => true,
                'limit' => 5,
            ]);
        }

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($user, $query, $search)],
        ];
        foreach (array_slice($history, -10) as $turn) {
            $role = (string) ($turn['role'] ?? '');
            $content = trim((string) ($turn['content'] ?? ''));
            if (! in_array($role, ['user', 'assistant'], true) || $content === '') {
                continue;
            }
            $messages[] = ['role' => $role, 'content' => mb_substr($content, 0, 2000)];
        }
        $messages[] = ['role' => 'user', 'content' => mb_substr(trim($prompt), 0, 2000)];

        $result = $this->workersAi->complete($messages, 700);
        if ($result['ok']) {
            $this->usage->increment('workers_ai', 'requests');
        }

        $itineraries = (! empty($search['ok']) && is_array($search['itineraries'] ?? null))
            ? $search['itineraries']
            : [];

        return [
            'ok' => $result['ok'],
            'text' => $result['text'],
            'message' => $result['message'],
            'searched' => $search !== null,
            'itineraries' => $itineraries,
            'search' => $search === null ? null : [
                'from' => $query['from'],
                'to' => $query['to'],
                'departureAt' => $query['departureAt'],
                'timeType' => $query['timeType'],
                'preference' => $query['preference'],
                'ok' => ! empty($search['ok']),
                'engine' => (string) ($search['engine'] ?? ''),
                'engineNote' => (string) ($search['engineNote'] ?? ''),
                'message' => (string) ($search['message'] ?? ''),
            ],
        ];
    }

    /**
     * @param  list<array{role?: mixed, content?: mixed}>  $history
     * @param  array<string, mixed>  $context
     * @return array{from: string, to: string, departureAt: string, timeType: string, preference: string}
     */
    public function resolveQuery(User $user, string $prompt, array $history, array $context): array
    {
        $last = is_array($context['lastSearch'] ?? null) ? $context['lastSearch'] : [];
        $from = $this->place((string) ($context['from'] ?? ''))
            ?: $this->place((string) ($last['from'] ?? ''));
        $to = $this->place((string) ($context['to'] ?? ''))
            ?: $this->place((string) ($last['to'] ?? ''));
        $preference = $this->preference((string) ($context['preference'] ?? ''))
            ?: $this->preference((string) ($last['preference'] ?? ''))
            ?: ItineraryScorer::PREF_FASTEST;
        $timeType = ((string) ($context['timeType'] ?? $last['timeType'] ?? 'departure')) === 'arrival'
            ? 'arrival'
            : 'departure';
        $departureAt = $this->datetime((string) ($context['departureAt'] ?? ''))
            ?: $this->datetime((string) ($last['departureAt'] ?? ''))
            ?: now()->format('Y-m-d\TH:i');

        $fromPrompt = $this->extractPair($prompt);
        if ($fromPrompt[0] !== '' && $fromPrompt[1] !== '') {
            $from = $fromPrompt[0];
            $to = $fromPrompt[1];
        } elseif ($from === '' || $to === '') {
            foreach (array_reverse($history) as $turn) {
                $pair = $this->extractPair((string) ($turn['content'] ?? ''));
                if ($pair[0] !== '' && $pair[1] !== '') {
                    $from = $from !== '' ? $from : $pair[0];
                    $to = $to !== '' ? $to : $pair[1];
                    break;
                }
            }
        }

        $prefFromText = $this->preferenceFromText($prompt);
        if ($prefFromText !== '') {
            $preference = $prefFromText;
        }
        $timeFromText = $this->timeFromText($prompt);
        if ($timeFromText !== null) {
            $departureAt = $timeFromText['departureAt'];
            $timeType = $timeFromText['timeType'];
        }

        if ($from === '' || $to === '') {
            [$from, $to] = $this->fromFavorite($user, $prompt, $from, $to);
        }

        return [
            'from' => $from,
            'to' => $to,
            'departureAt' => $departureAt,
            'timeType' => $timeType,
            'preference' => $preference,
        ];
    }

    /** @param array<string, mixed>|null $search */
    private function systemPrompt(User $user, array $query, ?array $search): string
    {
        $locale = app()->getLocale() === 'en' ? 'English' : 'Japanese';
        $base = 'You are the transit assistant inside sa2-plus. Answer in '.$locale.'. '
            .'Keep answers short and practical. '
            .'Times, transfers, fares, company names, and line names MUST come from the route-search API result below. '
            .'Never invent a timetable, fare, or route that is not in the API result. '
            .'If the user wants a cheaper / faster / fewer-transfer option, say we can search again with that preference. '
            .'Ignore times and fares from earlier chat turns when a newer API result is present.';

        return trim($base."\n\n".$this->factsBlock($user, $query, $search));
    }

    /** @param array<string, mixed>|null $search */
    private function factsBlock(User $user, array $query, ?array $search): string
    {
        if ($query['from'] === '' || $query['to'] === '') {
            return "No route search yet (origin or destination missing).\n"
                .$this->favoriteLines($user)."\n"
                .'Ask which stations to search. Do not invent times or fares.';
        }

        $header = 'Query: '.$query['from'].' → '.$query['to']
            .' at '.$query['departureAt'].' ('.$query['timeType'].', '.$query['preference'].')';

        if ($search === null) {
            return $header."\nSearch was not run. Do not invent times or fares.";
        }
        if (empty($search['ok'])) {
            return $header."\nSearch failed: ".((string) ($search['message'] ?? ''))."\n"
                .'Explain that we could not get a timetable. Suggest nearby station names. Do not invent times or fares.';
        }

        $engine = (string) ($search['engine'] ?? '');
        $note = (string) ($search['engineNote'] ?? '');
        $lines = [
            $header,
            'Engine: '.$engine.($note !== '' ? ' ('.$note.')' : ''),
            'API itineraries (source of truth):',
        ];
        foreach (array_slice($search['itineraries'] ?? [], 0, 5) as $index => $itinerary) {
            if (! is_array($itinerary)) {
                continue;
            }
            $lines[] = '#'.($index + 1).' '.trim((string) ($itinerary['summary'] ?? '経路'))
                .' | '.(string) ($itinerary['departureTime'] ?? '').'→'.(string) ($itinerary['arrivalTime'] ?? '')
                .' | '.(string) ($itinerary['durationLabel'] ?? '')
                .' | 乗換'.(string) ($itinerary['transfers'] ?? 0)
                .' | '.(string) ($itinerary['fareLabel'] ?? '');
            foreach (array_slice($itinerary['legs'] ?? [], 0, 8) as $leg) {
                if (! is_array($leg)) {
                    continue;
                }
                if (($leg['type'] ?? '') === 'walk') {
                    $lines[] = '  - walk '.(string) ($leg['from'] ?? '').' → '.(string) ($leg['to'] ?? '')
                        .' ('.round(((int) ($leg['durationSec'] ?? 0)) / 60).' min)';

                    continue;
                }
                $lines[] = '  - '.(string) ($leg['routeName'] ?? '')
                    .' '.(string) ($leg['boardTime'] ?? '').' '.(string) ($leg['from'] ?? '')
                    .' → '.(string) ($leg['alightTime'] ?? '').' '.(string) ($leg['to'] ?? '');
            }
        }

        return implode("\n", $lines);
    }

    private function favoriteLines(User $user): string
    {
        try {
            $rows = TransitFavorite::query()
                ->where('user_id', $user->id)
                ->orderBy('sort_order')
                ->limit(8)
                ->get();
        } catch (\Throwable) {
            return 'Saved routes: none.';
        }
        if ($rows->isEmpty()) {
            return 'Saved routes: none.';
        }
        $lines = ['Saved routes:'];
        foreach ($rows as $row) {
            $lines[] = '- '.trim((string) $row->name).': '
                .trim((string) $row->from_place).' → '.trim((string) $row->to_place);
        }

        return implode("\n", $lines);
    }

    /** @return array{0: string, 1: string} */
    private function extractPair(string $text): array
    {
        $text = str_replace(['⇒', '➡', '->', '＞', '〜', '～', '―'], '→', $text);
        if (preg_match('/(.{1,30}?)から(.{1,30}?)まで/u', $text, $m)) {
            return [$this->place($m[1]), $this->place($m[2])];
        }
        if (preg_match('/(.{1,30}?)から(.{1,30}?)(?:へ|に)(?:行き|行っ|向か)/u', $text, $m)) {
            return [$this->place($m[1]), $this->place($m[2])];
        }
        if (preg_match('/(.{1,24}?)→(.{1,24})/u', $text, $m)) {
            return [$this->place($m[1]), $this->place($m[2])];
        }
        if (preg_match('/\bfrom\s+(.{1,40}?)\s+to\s+(.{1,40}?)(?:[.?!]|$)/i', $text, $m)) {
            return [$this->place($m[1]), $this->place($m[2])];
        }

        return ['', ''];
    }

    private function place(string $raw): string
    {
        $raw = trim($raw);
        $raw = preg_replace('/^(出発地|到着地|出発|到着)[:：\s]*/u', '', $raw) ?? $raw;
        $raw = preg_replace('/の(?:行き方|ルート|経路|乗換|乗り換え|案内).*$/u', '', $raw) ?? $raw;
        $raw = preg_replace('/(?:を教えて.*|ってどう.*|はどう.*)$/u', '', $raw) ?? $raw;
        $raw = trim((string) $raw, " 　「」『』\"'。、,.?？!！");

        return mb_strlen($raw) >= 1 && mb_strlen($raw) <= 40 ? $raw : '';
    }

    private function preference(string $raw): string
    {
        return in_array($raw, [
            ItineraryScorer::PREF_FASTEST,
            ItineraryScorer::PREF_CHEAPEST,
            ItineraryScorer::PREF_FEWEST_TRANSFERS,
        ], true) ? $raw : '';
    }

    private function preferenceFromText(string $text): string
    {
        if (preg_match('/安|最安|料金|運賃|安い/u', $text)) {
            return ItineraryScorer::PREF_CHEAPEST;
        }
        if (preg_match('/乗換少|乗換が少|乗り換えが少|雨|濡れ|楽な|少ない乗換/u', $text)) {
            return ItineraryScorer::PREF_FEWEST_TRANSFERS;
        }
        if (preg_match('/早|最短|急行|最速/u', $text)) {
            return ItineraryScorer::PREF_FASTEST;
        }

        return '';
    }

    /** @return array{departureAt: string, timeType: string}|null */
    private function timeFromText(string $text): ?array
    {
        $timeType = preg_match('/到着/u', $text) ? 'arrival' : 'departure';
        if (preg_match('/始発/u', $text)) {
            return ['departureAt' => now()->format('Y-m-d').'T05:00', 'timeType' => 'departure'];
        }
        if (preg_match('/終電|最終/u', $text)) {
            return ['departureAt' => now()->format('Y-m-d').'T23:30', 'timeType' => 'departure'];
        }
        if (preg_match('/(\d{1,2})\s*時\s*(\d{1,2})?\s*分?/u', $text, $m)) {
            $hour = max(0, min(23, (int) $m[1]));
            $minute = isset($m[2]) && $m[2] !== '' ? max(0, min(59, (int) $m[2])) : 0;

            return [
                'departureAt' => now()->format('Y-m-d').'T'.sprintf('%02d:%02d', $hour, $minute),
                'timeType' => $timeType,
            ];
        }

        return null;
    }

    private function datetime(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/', $raw, $m)) {
            return $m[1].'T'.$m[2];
        }

        return '';
    }

    /** @return array{0: string, 1: string} */
    private function fromFavorite(User $user, string $prompt, string $from, string $to): array
    {
        try {
            $rows = TransitFavorite::query()
                ->where('user_id', $user->id)
                ->orderBy('sort_order')
                ->limit(10)
                ->get();
        } catch (\Throwable) {
            return [$from, $to];
        }
        foreach ($rows as $row) {
            $name = trim((string) $row->name);
            if ($name !== '' && mb_strlen($prompt) > 0 && mb_strpos($prompt, $name) !== false) {
                return [
                    $from !== '' ? $from : $this->place((string) $row->from_place),
                    $to !== '' ? $to : $this->place((string) $row->to_place),
                ];
            }
        }
        if ($rows->count() === 1 && $from === '' && $to === '') {
            $row = $rows->first();

            return [
                $this->place((string) $row->from_place),
                $this->place((string) $row->to_place),
            ];
        }

        return [$from, $to];
    }
}
