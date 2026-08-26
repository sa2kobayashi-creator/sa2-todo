<?php

namespace App\Services\Transit;

use App\Models\MediaStorageSetting;
use App\Services\Transit\Contracts\RouteProvider;
use App\Services\Transit\Providers\EkispertRouteProvider;
use App\Services\Transit\Providers\GoogleRoutesRouteProvider;
use App\Services\Transit\Providers\NavitimeRouteProvider;
use App\Services\Transit\Providers\RaptorRouteProvider;
use App\Services\Transit\Raptor\ItineraryScorer;

/**
 * 路線検索の窓口。どの API を使うかをここだけで決める。
 *
 * 優先順は「設定画面の選択 ＞ ROUTE_PROVIDER ＞ auto」。auto と、選んだ API が
 * 落ちたときは、使える次のプロバイダへ自動で回して結果を必ず返す。
 * 並びは Google Maps Routes → NAVITIME → 駅すぱあと → 内蔵 RAPTOR。
 */
class RouteSearchService
{
    public const AUTO = 'auto';

    /** @var list<RouteProvider> 上から優先 */
    private array $providers;

    public function __construct(
        GoogleRoutesRouteProvider $google,
        NavitimeRouteProvider $navitime,
        EkispertRouteProvider $ekispert,
        RaptorRouteProvider $raptor,
        private TransitOperatorCatalog $operators,
        private ItineraryScorer $scorer,
    ) {
        $this->providers = [$google, $navitime, $ekispert, $raptor];
    }

    /** @return array<string, RouteProvider> */
    public function all(): array
    {
        $keyed = [];
        foreach ($this->providers as $provider) {
            $keyed[$provider->key()] = $provider;
        }

        return $keyed;
    }

    public function provider(string $key): ?RouteProvider
    {
        return $this->all()[$key] ?? null;
    }

    /** 設定画面のセレクト用 */
    public function options(): array
    {
        $options = [self::AUTO => __('自動（使えるものを上から順に）')];
        foreach ($this->providers as $provider) {
            $options[$provider->key()] = $provider->label();
        }

        return $options;
    }

    public function selectedKey(): string
    {
        $row = MediaStorageSetting::forUse(MediaStorageSetting::PROVIDER_ROUTE_SEARCH);
        $fromDb = $this->normalizeKey((string) $row->setting('engine', ''));
        if ($fromDb !== '') {
            return $fromDb;
        }

        $fromEnv = $this->normalizeKey((string) config('transit.provider', self::AUTO));

        return $fromEnv !== '' ? $fromEnv : self::AUTO;
    }

    public function saveSelectedKey(string $key): void
    {
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_ROUTE_SEARCH);
        $row->fill([
            'enabled' => true,
            'settings' => ['engine' => $this->normalizeKey($key) ?: self::AUTO],
            'secrets' => $row->secretsArray(),
        ]);
        $row->save();
    }

    /** いま実際に使われるプロバイダ（未設定のものは飛ばす） */
    public function activeProvider(): ?RouteProvider
    {
        foreach ($this->chainFor($this->selectedKey()) as $provider) {
            if ($provider->isReady()) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{ok: bool, engine?: string, message?: string, itineraries: list<array<string, mixed>>}
     */
    public function search(array $query): array
    {
        $requested = $this->normalizeKey((string) ($query['engine'] ?? '')) ?: $this->selectedKey();
        $chain = $this->chainFor($requested);

        $failed = null;
        $lastResult = null;

        foreach ($chain as $provider) {
            if (! $provider->isReady()) {
                continue;
            }

            $result = $this->runProvider($provider, $query);
            $lastResult = $result;

            if (! empty($result['ok'])) {
                if ($failed !== null) {
                    $result['engineNote'] = __(':label で取得できなかったため :engine で検索しました（:reason）', [
                        'label' => $failed['label'],
                        'engine' => $provider->label(),
                        'reason' => $failed['reason'],
                    ]);
                }

                return $this->applyPreferredOperator($result, $query);
            }

            $failed ??= [
                'label' => $provider->label(),
                'reason' => (string) ($result['message'] ?? ''),
            ];
        }

        return $lastResult ?? [
            'ok' => false,
            'message' => __('使える経路検索 API がありません。設定 → API設定 を確認してください。'),
            'itineraries' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{ok: bool, engine?: string, message?: string, itineraries: list<array<string, mixed>>}
     */
    private function runProvider(RouteProvider $provider, array $query): array
    {
        try {
            $result = $provider->search($query);
        } catch (\Throwable $e) {
            report($e);

            return [
                'ok' => false,
                'message' => mb_substr($e->getMessage(), 0, 200),
                'itineraries' => [],
            ];
        }

        $result['engine'] ??= $provider->label();

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function applyPreferredOperator(array $result, array $query): array
    {
        $operatorId = trim((string) ($query['preferredOperator'] ?? ''));
        $result = $this->operators->markItineraries($result, $operatorId);
        $itineraries = is_array($result['itineraries'] ?? null) ? $result['itineraries'] : [];
        if ($itineraries === []) {
            return $result;
        }

        $preference = (string) ($query['preference'] ?? ItineraryScorer::PREF_FASTEST);
        $limit = max(1, min(10, (int) ($query['limit'] ?? 8)));
        $ranked = $this->scorer->rank($itineraries, $preference, $operatorId !== '');
        $result['itineraries'] = $operatorId !== ''
            ? $this->pinPreferred($ranked, $limit)
            : $this->diversifyByAgency($ranked, $limit);

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $itineraries
     * @return list<array<string, mixed>>
     */
    private function pinPreferred(array $itineraries, int $limit): array
    {
        $preferred = [];
        $others = [];
        foreach ($itineraries as $itinerary) {
            if (! empty($itinerary['usesPreferredOperator'])) {
                $preferred[] = $itinerary;
            } else {
                $others[] = $itinerary;
            }
        }

        return array_slice(array_values(array_merge($preferred, $others)), 0, $limit);
    }

    /**
     * @param  list<array<string, mixed>>  $itineraries
     * @return list<array<string, mixed>>
     */
    private function diversifyByAgency(array $itineraries, int $limit): array
    {
        $buckets = [];
        foreach ($itineraries as $itinerary) {
            $buckets[$this->rideAgency($itinerary)][] = $itinerary;
        }

        $mixed = [];
        $index = 0;
        while (count($mixed) < $limit) {
            $added = false;
            foreach ($buckets as $agency => $list) {
                if (! isset($list[$index])) {
                    continue;
                }
                $mixed[] = $list[$index];
                $added = true;
                if (count($mixed) >= $limit) {
                    break;
                }
            }
            if (! $added) {
                break;
            }
            $index++;
        }

        return $mixed;
    }

    /** @param  array<string, mixed>  $itinerary */
    private function rideAgency(array $itinerary): string
    {
        foreach ($itinerary['legs'] ?? [] as $leg) {
            if (! is_array($leg) || ($leg['type'] ?? '') === 'walk') {
                continue;
            }
            $agency = trim((string) ($leg['agency'] ?? ''));
            if ($agency !== '') {
                return $agency;
            }
            $name = trim((string) ($leg['routeName'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return 'other';
    }

    /** @return list<RouteProvider> */
    private function chainFor(string $key): array
    {
        if ($key === self::AUTO || $this->provider($key) === null) {
            return $this->providers;
        }

        $head = $this->provider($key);
        $rest = array_values(array_filter($this->providers, fn (RouteProvider $p) => $p->key() !== $key));

        return [$head, ...$rest];
    }

    private function normalizeKey(string $key): string
    {
        $key = trim(strtolower($key));
        if ($key === self::AUTO) {
            return self::AUTO;
        }

        return $this->provider($key) !== null ? $key : '';
    }
}
