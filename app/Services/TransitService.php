<?php

namespace App\Services;

use App\Models\TransitFavorite;

class TransitService
{
    public const ALL_CATEGORY = 'all';

    public const CATEGORY_LABELS = [
        'nishitetsu_bus' => '西鉄バス',
        'jr' => 'JR',
        'ferry' => '市営渡船',
        'nishitetsu_rail' => '西鉄電車',
        'subway' => '地下鉄',
    ];

    public const CATEGORY_ICONS = [
        'nishitetsu_bus' => '🚌',
        'jr' => '🚃',
        'ferry' => '⛴',
        'nishitetsu_rail' => '🚋',
        'subway' => '🚇',
    ];

    /** タブ表示用（先頭に「すべて」= 乗り継ぎ検索） */
    public const TAB_LABELS = [
        self::ALL_CATEGORY => 'すべて',
        'nishitetsu_bus' => '西鉄バス',
        'jr' => 'JR',
        'ferry' => '市営渡船',
        'nishitetsu_rail' => '西鉄電車',
        'subway' => '地下鉄',
    ];

    public const TAB_ICONS = [
        self::ALL_CATEGORY => '🔀',
        'nishitetsu_bus' => '🚌',
        'jr' => '🚃',
        'ferry' => '⛴',
        'nishitetsu_rail' => '🚋',
        'subway' => '🚇',
    ];

    public const TRAVEL_MODE_LABELS = [
        'transit' => '公共交通',
        'driving' => '車',
        'walking' => '徒歩',
        'bicycling' => '自転車',
    ];

    /** @var array<string, array{label: string, url: string}> */
    public const EXTERNAL_SEARCH = [
        'nishitetsu_bus' => [
            'label' => '西鉄バス 時刻検索',
            'url' => 'https://www.nishitetsu.jp/bus/',
        ],
        'jr' => [
            'label' => 'JR 時刻・運賃検索',
            'url' => 'https://www.jrkyushu.co.jp/',
        ],
        'ferry' => [
            'label' => '福岡市 渡船案内',
            'url' => 'https://www.city.fukuoka.lg.jp/kyoiku/bunka/ferry.html',
        ],
        'nishitetsu_rail' => [
            'label' => '西鉄電車 時刻検索',
            'url' => 'https://www.nishitetsu.co.jp/',
        ],
        'subway' => [
            'label' => '福岡市地下鉄',
            'url' => 'https://subway.city.fukuoka.lg.jp/',
        ],
    ];

    /** タブ選択用（「すべて」を含む）。無効値は「すべて」にフォールバック */
    public function normalizeCategory(?string $category): string
    {
        return array_key_exists($category, self::TAB_LABELS) ? $category : self::ALL_CATEGORY;
    }

    /** 保存用カテゴリ（「すべて」は不可）。無効値は西鉄バスにフォールバック */
    public function normalizeStorableCategory(?string $category): string
    {
        return array_key_exists($category, self::CATEGORY_LABELS) ? $category : 'nishitetsu_bus';
    }

    /** @return array{category: string} */
    public function parseFilters(array $query): array
    {
        return [
            'category' => $this->normalizeCategory($query['category'] ?? null),
        ];
    }

    /** @param array{category?: string} $filters */
    public function buildTransitQuery(array $filters): string
    {
        $category = $filters['category'] ?? self::ALL_CATEGORY;
        if ($category === self::ALL_CATEGORY) {
            return '/transit';
        }

        return '/transit?'.http_build_query(['category' => $category]);
    }

    /** @return list<array<string, mixed>> */
    public function listFavorites(int $userId, ?string $category = null): array
    {
        $query = TransitFavorite::query()
            ->where('user_id', $userId)
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($category !== null) {
            $query->where('category', $category);
        }

        return $query->get()->map(fn (TransitFavorite $item) => $this->toArray($item))->all();
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function groupFavoritesByCategory(int $userId): array
    {
        $grouped = [];
        foreach (array_keys(self::CATEGORY_LABELS) as $key) {
            $grouped[$key] = [];
        }

        foreach ($this->listFavorites($userId) as $item) {
            $grouped[$item['category']][] = $item;
        }

        return $grouped;
    }

    /** @return array<string, mixed> */
    public function toArray(TransitFavorite $item): array
    {
        return [
            'id' => $item->id,
            'category' => $item->category,
            'categoryLabel' => self::CATEGORY_LABELS[$item->category] ?? $item->category,
            'categoryIcon' => self::CATEGORY_ICONS[$item->category] ?? '🚏',
            'name' => $item->name,
            'fromPlace' => $item->from_place,
            'toPlace' => $item->to_place,
            'lineName' => $item->line_name ?? '',
            'notes' => $item->notes ?? '',
            'sortOrder' => $item->sort_order,
            'googleMapsUrl' => $this->buildGoogleMapsTransitUrl($item->from_place, $item->to_place),
            'yahooTransitUrl' => $this->buildYahooTransitUrl($item->from_place, $item->to_place),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function createFavorite(int $userId, array $payload): TransitFavorite
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('名称を入力してください');
        }

        $maxOrder = (int) TransitFavorite::query()
            ->where('user_id', $userId)
            ->where('category', $this->normalizeStorableCategory($payload['category'] ?? null))
            ->max('sort_order');

        return TransitFavorite::query()->create([
            'user_id' => $userId,
            'category' => $this->normalizeStorableCategory($payload['category'] ?? null),
            'name' => $name,
            'from_place' => trim((string) ($payload['fromPlace'] ?? '')),
            'to_place' => trim((string) ($payload['toPlace'] ?? '')),
            'line_name' => trim((string) ($payload['lineName'] ?? '')) ?: null,
            'notes' => trim((string) ($payload['notes'] ?? '')) ?: null,
            'sort_order' => $maxOrder + 10,
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function updateFavorite(int $userId, int $id, array $payload): bool
    {
        $item = TransitFavorite::query()->where('user_id', $userId)->whereKey($id)->first();
        if (! $item) {
            return false;
        }

        $name = trim((string) ($payload['name'] ?? $item->name));
        if ($name === '') {
            throw new \InvalidArgumentException('名称を入力してください');
        }

        return $item->update([
            'category' => $this->normalizeStorableCategory($payload['category'] ?? $item->category),
            'name' => $name,
            'from_place' => trim((string) ($payload['fromPlace'] ?? $item->from_place)),
            'to_place' => trim((string) ($payload['toPlace'] ?? $item->to_place)),
            'line_name' => trim((string) ($payload['lineName'] ?? $item->line_name ?? '')) ?: null,
            'notes' => trim((string) ($payload['notes'] ?? $item->notes ?? '')) ?: null,
        ]);
    }

    public function deleteFavorite(int $userId, int $id): bool
    {
        return (bool) TransitFavorite::query()->where('user_id', $userId)->whereKey($id)->delete();
    }

    public function buildGoogleMapsTransitUrl(string $from, string $to): string
    {
        if ($from === '' || $to === '') {
            return 'https://www.google.com/maps/dir/?api=1&travelmode=transit';
        }

        return 'https://www.google.com/maps/dir/?api=1&travelmode=transit&origin='
            .urlencode($from)
            .'&destination='
            .urlencode($to);
    }

    public function buildYahooTransitUrl(string $from, string $to): string
    {
        if ($from === '' || $to === '') {
            return 'https://transit.yahoo.co.jp/';
        }

        return 'https://transit.yahoo.co.jp/search/result?from='
            .urlencode($from)
            .'&to='
            .urlencode($to);
    }

    public function buildQuickSearchUrls(string $from, string $to, string $category): array
    {
        $mode = $category === 'ferry' ? 'transit' : 'transit';

        return [
            'googleMaps' => $this->buildGoogleMapsTransitUrl($from, $to),
            'yahooTransit' => $this->buildYahooTransitUrl($from, $to),
            'googleNav' => 'https://www.google.com/maps/dir/?api=1&travelmode='.$mode
                .'&origin='.urlencode($from)
                .'&destination='.urlencode($to),
        ];
    }
}
