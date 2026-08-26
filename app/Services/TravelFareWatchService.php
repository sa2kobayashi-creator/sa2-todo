<?php

namespace App\Services;

use App\Models\TravelFareWatch;

class TravelFareWatchService
{
    public const MAX_PER_USER = 10;

    public function __construct(
        private TravelFareTableService $fareTable,
        private TravelService $travel,
        private TravelAirportSuggestService $airports,
    ) {}

    /** @return list<array<string, mixed>> */
    public function listForUser(int $userId): array
    {
        return TravelFareWatch::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (TravelFareWatch $watch) => $this->toArray($watch))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(int $userId, array $payload): TravelFareWatch
    {
        $count = TravelFareWatch::query()->where('user_id', $userId)->count();
        if ($count >= self::MAX_PER_USER) {
            throw new \InvalidArgumentException(__('保存できる検索は :n 件までです。', ['n' => self::MAX_PER_USER]));
        }

        $data = $this->normalize($payload);

        return TravelFareWatch::query()->create(array_merge($data, ['user_id' => $userId]));
    }

    public function delete(int $userId, int $id): bool
    {
        $watch = TravelFareWatch::query()->where('user_id', $userId)->where('id', $id)->first();
        if (! $watch) {
            return false;
        }
        $watch->delete();

        return true;
    }

    /**
     * @return array{checked: int, alerts: int, errors: int}
     */
    public function checkAllWatches(): array
    {
        $stats = ['checked' => 0, 'alerts' => 0, 'errors' => 0];
        TravelFareWatch::query()
            ->orderBy('id')
            ->each(function (TravelFareWatch $watch) use (&$stats) {
                $stats['checked']++;
                try {
                    $stats['alerts'] += $this->checkWatch($watch);
                } catch (\Throwable) {
                    $stats['errors']++;
                }
            });

        return $stats;
    }

    public function checkForUser(int $userId, int $id): int
    {
        $watch = TravelFareWatch::query()->where('user_id', $userId)->where('id', $id)->first();
        if (! $watch) {
            throw new \InvalidArgumentException(__('保存した検索が見つかりません。'));
        }

        return $this->checkWatch($watch);
    }

    /** @return array<string, mixed> */
    public function toArray(TravelFareWatch $watch): array
    {
        return [
            'id' => $watch->id,
            'origin' => (string) $watch->origin,
            'destination' => (string) $watch->destination,
            'airlineCode' => (string) ($watch->airline_code ?? ''),
            'mode' => (string) $watch->mode,
            'currency' => (string) $watch->currency,
            'departFrom' => $watch->depart_from?->format('Y-m-d'),
            'departTo' => $watch->depart_to?->format('Y-m-d'),
            'returnFrom' => $watch->return_from?->format('Y-m-d'),
            'returnTo' => $watch->return_to?->format('Y-m-d'),
            'maxPrice' => $watch->max_price,
            'lastBestPrice' => $watch->last_best_price,
            'lastBestOn' => (string) ($watch->last_best_on ?? ''),
            'lastCheckedAt' => $watch->last_checked_at?->format('Y-m-d H:i'),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalize(array $payload): array
    {
        $origin = $this->airports->resolveCode((string) ($payload['origin'] ?? ''));
        $destination = $this->airports->resolveCode((string) ($payload['destination'] ?? ''));
        if ($origin === '' || $destination === '') {
            throw new \InvalidArgumentException(__('出発空港と到着空港を入力してください。'));
        }

        $mode = ($payload['mode'] ?? '') === 'rt' ? 'rt' : 'ow';
        $currency = strtoupper((string) ($payload['currency'] ?? 'JPY')) === 'PHP' ? 'PHP' : 'JPY';
        $departFrom = trim((string) ($payload['departFrom'] ?? ''));
        $departTo = trim((string) ($payload['departTo'] ?? ''));
        if ($departFrom === '' || $departTo === '') {
            throw new \InvalidArgumentException(__('出発期間を入力してください。'));
        }

        $returnFrom = trim((string) ($payload['returnFrom'] ?? ''));
        $returnTo = trim((string) ($payload['returnTo'] ?? ''));
        if ($mode === 'rt' && ($returnFrom === '' || $returnTo === '')) {
            throw new \InvalidArgumentException(__('往復の場合は帰国期間も入力してください。'));
        }

        $airline = strtoupper(trim((string) ($payload['airlineCode'] ?? '')));
        $maxPrice = $payload['maxPrice'] ?? null;
        $maxPrice = ($maxPrice === '' || $maxPrice === null) ? null : max(1, (int) $maxPrice);

        return [
            'origin' => $origin,
            'destination' => $destination,
            'airline_code' => $airline !== '' ? $airline : null,
            'mode' => $mode,
            'currency' => $currency,
            'depart_from' => $departFrom,
            'depart_to' => $departTo,
            'return_from' => $mode === 'rt' ? $returnFrom : null,
            'return_to' => $mode === 'rt' ? $returnTo : null,
            'max_price' => $maxPrice,
        ];
    }

    private function checkWatch(TravelFareWatch $watch): int
    {
        $table = $this->fareTable->build(
            (string) $watch->mode,
            (string) $watch->depart_from?->format('Y-m-d'),
            (string) $watch->depart_to?->format('Y-m-d'),
            $watch->return_from?->format('Y-m-d'),
            $watch->return_to?->format('Y-m-d'),
            (string) $watch->origin,
            (string) $watch->destination,
            (string) ($watch->airline_code ?? ''),
            (string) $watch->currency,
        );

        $cheapest = $table['cheapest'][0] ?? null;
        $priceKey = $watch->currency === 'JPY' ? 'priceJpy' : 'pricePhp';
        $best = is_array($cheapest) ? ($cheapest[$priceKey] ?? null) : null;
        $bestOn = '';
        if (is_array($cheapest)) {
            $bestOn = (string) ($cheapest['departOn'] ?? '');
            if (! empty($cheapest['returnOn'])) {
                $bestOn .= ' / '.$cheapest['returnOn'];
            }
        }

        $previous = $watch->last_best_price;
        $watch->fill([
            'last_best_price' => $best !== null ? (int) $best : $watch->last_best_price,
            'last_best_on' => $bestOn !== '' ? $bestOn : $watch->last_best_on,
            'last_checked_at' => now(),
        ]);
        $watch->save();

        if ($best === null) {
            return 0;
        }

        $created = 0;
        $route = $watch->origin.'→'.$watch->destination;
        $priceLabel = $watch->currency === 'JPY'
            ? '¥'.number_format((int) $best)
            : '₱'.number_format((int) $best);

        if ($previous !== null && (int) $best < (int) $previous) {
            $created += $this->travel->upsertAlert(
                (int) $watch->user_id,
                'fare_drop',
                'info',
                __('値下がり: :route', ['route' => $route]),
                __('最安が :old から :new になりました（:when）。', [
                    'old' => $watch->currency === 'JPY' ? '¥'.number_format((int) $previous) : '₱'.number_format((int) $previous),
                    'new' => $priceLabel,
                    'when' => $bestOn !== '' ? $bestOn : __('期間内'),
                ]),
                'watch:drop:'.$watch->id.':'.$best
            );
        }

        if ($watch->max_price !== null && (int) $best <= (int) $watch->max_price) {
            $created += $this->travel->upsertAlert(
                (int) $watch->user_id,
                'watch',
                'info',
                __('予算内: :route', ['route' => $route]),
                __('最安 :price が上限 :max 以下です。', [
                    'price' => $priceLabel,
                    'max' => $watch->currency === 'JPY'
                        ? '¥'.number_format((int) $watch->max_price)
                        : '₱'.number_format((int) $watch->max_price),
                ]),
                'watch:budget:'.$watch->id.':'.$best
            );
        }

        return $created;
    }
}
