<?php

namespace App\Services;

use App\Models\TravelTrip;
use App\Models\User;
use Carbon\Carbon;

/**
 * 航空の AI 相談。相談 → 料金表 API → その数字だけを正として答える。
 */
class TravelAiConsultService
{
    public function __construct(
        private CloudflareWorkersAiConfigService $workersAi,
        private TravelFareTableService $fareTable,
        private TravelAirportSuggestService $airports,
        private TravelService $travel,
        private IntegrationUsageService $usage,
    ) {}

    /**
     * @param  list<array{role?: mixed, content?: mixed}>  $history
     * @param  array<string, mixed>  $context
     * @return array{ok: bool, text: string, message: string, searched: bool, search: array<string, mixed>|null}
     */
    public function ask(User $user, string $prompt, array $history = [], array $context = []): array
    {
        $query = $this->resolveQuery($user, $prompt, $history, $context);
        $table = null;
        $fareError = '';
        if ($query['origin'] !== '' && $query['destination'] !== '') {
            try {
                $table = $this->fareTable->build(
                    $query['mode'],
                    $query['departFrom'],
                    $query['departTo'],
                    $query['mode'] === 'rt' ? $query['returnFrom'] : null,
                    $query['mode'] === 'rt' ? $query['returnTo'] : null,
                    $query['origin'],
                    $query['destination'],
                    $query['airlineCode'],
                    $query['currency'],
                );
            } catch (\Throwable $e) {
                $fareError = $e->getMessage();
            }
        }

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($user, $query, $table, $fareError)],
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

        return [
            'ok' => $result['ok'],
            'text' => $result['text'],
            'message' => $result['message'],
            'searched' => $table !== null,
            'search' => $table === null ? null : [
                'origin' => $query['origin'],
                'destination' => $query['destination'],
                'mode' => $query['mode'],
                'currency' => $query['currency'],
                'departFrom' => $query['departFrom'],
                'departTo' => $query['departTo'],
            ],
        ];
    }

    /**
     * @param  list<array{role?: mixed, content?: mixed}>  $history
     * @param  array<string, mixed>  $context
     * @return array{origin: string, destination: string, mode: string, currency: string, airlineCode: string, departFrom: string, departTo: string, returnFrom: string, returnTo: string}
     */
    public function resolveQuery(User $user, string $prompt, array $history, array $context): array
    {
        $profile = $this->travel->getOrCreateProfile((int) $user->id);
        $last = is_array($context['lastSearch'] ?? null) ? $context['lastSearch'] : [];

        $origin = $this->airports->resolveCode((string) ($context['origin'] ?? $last['origin'] ?? $profile->home_airport ?? ''));
        $destination = $this->airports->resolveCode((string) ($context['destination'] ?? $last['destination'] ?? $profile->ph_airport ?? ''));
        $mode = ((string) ($context['tableMode'] ?? $last['mode'] ?? 'ow')) === 'rt' ? 'rt' : 'ow';
        $currency = strtoupper((string) ($context['tableCurrency'] ?? $last['currency'] ?? $profile->preferred_currency ?? 'JPY')) === 'PHP'
            ? 'PHP'
            : 'JPY';
        $airline = strtoupper(trim((string) ($context['airlineCode'] ?? $last['airlineCode'] ?? '')));

        $pair = $this->extractPair($prompt);
        if ($pair[0] !== '' && $pair[1] !== '') {
            $origin = $pair[0];
            $destination = $pair[1];
        } elseif ($origin === '' || $destination === '') {
            foreach (array_reverse($history) as $turn) {
                $fromHistory = $this->extractPair((string) ($turn['content'] ?? ''));
                if ($fromHistory[0] !== '' && $fromHistory[1] !== '') {
                    $origin = $origin !== '' ? $origin : $fromHistory[0];
                    $destination = $destination !== '' ? $destination : $fromHistory[1];
                    break;
                }
            }
        }

        $range = $this->dateRangeFromText($prompt);
        $departFrom = trim((string) ($context['departFrom'] ?? $last['departFrom'] ?? '')) ?: $range['departFrom'];
        $departTo = trim((string) ($context['departTo'] ?? $last['departTo'] ?? '')) ?: $range['departTo'];
        $returnFrom = trim((string) ($context['returnFrom'] ?? $last['returnFrom'] ?? '')) ?: $range['returnFrom'];
        $returnTo = trim((string) ($context['returnTo'] ?? $last['returnTo'] ?? '')) ?: $range['returnTo'];

        if (str_contains($prompt, '往復') || preg_match('/\brt\b/i', $prompt)) {
            $mode = 'rt';
        } elseif (str_contains($prompt, '片道')) {
            $mode = 'ow';
        }

        return [
            'origin' => $origin,
            'destination' => $destination,
            'mode' => $mode,
            'currency' => $currency,
            'airlineCode' => $airline,
            'departFrom' => $departFrom,
            'departTo' => $departTo,
            'returnFrom' => $returnFrom,
            'returnTo' => $returnTo,
        ];
    }

    /** @return array{0: string, 1: string} */
    public function extractPair(string $text): array
    {
        if (preg_match('/\b([A-Za-z]{3})\s*[-–—→⇔~〜]+\s*([A-Za-z]{3})\b/', $text, $m)) {
            return [strtoupper($m[1]), strtoupper($m[2])];
        }
        if (preg_match('/(.+?)\s*(?:から|→|⇒)\s*(.+?)(?:\s*(?:まで|へ|の)|$)/u', $text, $m)) {
            $from = $this->airports->resolveCode(trim($m[1]));
            $to = $this->airports->resolveCode(preg_replace('/[のまへは].*$/u', '', trim($m[2])) ?? trim($m[2]));
            if ($from !== '' && $to !== '') {
                return [$from, $to];
            }
        }

        return ['', ''];
    }

    /**
     * @param  array<string, mixed>|null  $table
     */
    private function systemPrompt(User $user, array $query, ?array $table, string $fareError): string
    {
        $locale = app()->getLocale() === 'en' ? 'English' : 'Japanese';
        $base = 'You are the flight-fare assistant inside sa2-plus. Answer in '.$locale.'. '
            .'Keep answers short and practical. '
            .'Prices, dates, and airlines MUST come from the fare-table API result below. '
            .'Never invent a price or a cheaper date that is not in the API result. '
            .'Fares are cached estimates (Travelpayouts), not live airline inventory. '
            .'Tell the user to confirm on the airline site or Aviasales before booking. '
            .'If origin/destination is missing, ask for airports or city names.';

        return trim($base."\n\n".$this->factsBlock($user, $query, $table, $fareError));
    }

    /**
     * @param  array<string, mixed>|null  $table
     */
    private function factsBlock(User $user, array $query, ?array $table, string $fareError): string
    {
        $trips = $this->tripLines($user);
        if ($query['origin'] === '' || $query['destination'] === '') {
            return "No fare search yet (origin or destination missing).\n"
                .$trips."\n"
                .'Ask which airports or cities to search. Do not invent prices.';
        }

        $header = 'Query: '.$query['origin'].' → '.$query['destination']
            .' '.$query['departFrom'].'–'.$query['departTo']
            .' ('.$query['mode'].', '.$query['currency']
            .($query['airlineCode'] !== '' ? ', airline '.$query['airlineCode'] : ', any airline').')';

        if ($fareError !== '') {
            return $header."\nFare search failed: ".$fareError."\nDo not invent prices.\n".$trips;
        }
        if ($table === null) {
            return $header."\nFare search was not run. Do not invent prices.\n".$trips;
        }

        $lines = [
            $header,
            'Cached fare estimates (source of truth):',
        ];
        foreach (array_slice($table['cheapest'] ?? [], 0, 8) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $price = $query['currency'] === 'JPY' ? ($row['priceJpy'] ?? null) : ($row['pricePhp'] ?? null);
            $when = (string) ($row['departOn'] ?? '');
            if (! empty($row['returnOn'])) {
                $when .= ' / '.$row['returnOn'];
            }
            $airline = (string) ($row['airline'] ?? '');
            $lines[] = '- '.$when.' '.($price !== null ? (string) $price : 'n/a').' '.$query['currency']
                .($airline !== '' ? ' '.$airline : '');
        }
        if (count($lines) === 2) {
            $lines[] = '- no priced dates in this range';
        }
        $lines[] = $trips;

        return implode("\n", $lines);
    }

    private function tripLines(User $user): string
    {
        try {
            $rows = TravelTrip::query()
                ->where('user_id', $user->id)
                ->whereIn('status', ['planned', 'booked'])
                ->orderBy('depart_on')
                ->limit(6)
                ->get();
        } catch (\Throwable) {
            return 'Saved trips: none.';
        }
        if ($rows->isEmpty()) {
            return 'Saved trips: none.';
        }
        $lines = ['Saved trips:'];
        foreach ($rows as $row) {
            $lines[] = '- '.trim((string) ($row->label ?: $row->purpose))
                .' '.trim((string) ($row->origin ?? '')).' -> '.trim((string) ($row->destination ?? ''))
                .' '.(string) $row->depart_on?->format('Y-m-d');
        }

        return implode("\n", $lines);
    }

    /** @return array{departFrom: string, departTo: string, returnFrom: string, returnTo: string} */
    private function dateRangeFromText(string $prompt): array
    {
        $tz = config('app.timezone', 'Asia/Tokyo');
        $today = Carbon::now($tz)->startOfDay();
        if (str_contains($prompt, '来月')) {
            $from = $today->copy()->addMonth()->startOfMonth();
            $to = $from->copy()->endOfMonth();
        } elseif (str_contains($prompt, '来週')) {
            $from = $today->copy()->addWeek()->startOfWeek();
            $to = $from->copy()->endOfWeek();
        } else {
            $from = $today->copy();
            $to = $today->copy()->addDays(29);
        }
        if ($from->diffInDays($to) + 1 > 62) {
            $to = $from->copy()->addDays(61);
        }
        $returnFrom = $from->copy()->addDays(7);
        $returnTo = $to->copy()->addDays(7);

        return [
            'departFrom' => $from->format('Y-m-d'),
            'departTo' => $to->format('Y-m-d'),
            'returnFrom' => $returnFrom->format('Y-m-d'),
            'returnTo' => $returnTo->format('Y-m-d'),
        ];
    }
}
