<?php

namespace App\Support;

use App\Models\User;

class FooterNav
{
    /** Always available (no feature gate). */
    public const CORE = ['dashboard', 'todos', 'notes', 'photos'];

    /** Default visible footer slots (order matters). */
    public const DEFAULT_FOOTER = ['dashboard', 'todos', 'notes', 'photos', 'messages'];

    /** Max items in the bottom bar. */
    public const MAX_FOOTER = 5;

    /**
     * @return array<string, array{label: string, headerLabel: string, href: string, icon: string, feature: ?string}>
     */
    public static function catalog(): array
    {
        return [
            'dashboard' => ['label' => 'ホーム', 'headerLabel' => 'ダッシュボード', 'href' => '/dashboard', 'icon' => '📅', 'feature' => null],
            'todos' => ['label' => 'Todo', 'headerLabel' => 'Todo', 'href' => '/todos', 'icon' => '✓', 'feature' => null],
            'notes' => ['label' => 'メモ', 'headerLabel' => 'メモ', 'href' => '/notes', 'icon' => '📝', 'feature' => null],
            'photos' => ['label' => 'Photos', 'headerLabel' => 'Photos', 'href' => '/photos', 'icon' => '🖼', 'feature' => null],
            'messages' => ['label' => 'メッセージ', 'headerLabel' => 'メッセージ', 'href' => '/messages', 'icon' => '💬', 'feature' => 'messages'],
            'finance' => ['label' => '入出金', 'headerLabel' => '入出金経費', 'href' => '/finance', 'icon' => '💰', 'feature' => 'finance'],
            'music' => ['label' => '音楽', 'headerLabel' => '音楽', 'href' => '/music', 'icon' => '♪', 'feature' => 'music'],
            'video' => ['label' => '動画', 'headerLabel' => '動画', 'href' => '/video', 'icon' => '▶', 'feature' => 'video'],
            'translate' => ['label' => '翻訳', 'headerLabel' => '翻訳', 'href' => '/translate', 'icon' => '文A', 'feature' => 'translate'],
            'transit' => ['label' => '路線', 'headerLabel' => '路線検索', 'href' => '/transit', 'icon' => '🚌', 'feature' => 'transit'],
            'travel' => ['label' => 'Travel', 'headerLabel' => 'Travel', 'href' => '/travel', 'icon' => '✈', 'feature' => 'travel'],
            'map' => ['label' => 'マップ', 'headerLabel' => 'マップ', 'href' => '/map', 'icon' => '🗺', 'feature' => 'map'],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::catalog());
    }

    public static function canAccess(?User $user, string $key): bool
    {
        $item = self::catalog()[$key] ?? null;
        if ($item === null) {
            return false;
        }
        if ($item['feature'] === null) {
            return true;
        }
        if (! $user) {
            return false;
        }

        return $user->canAccess($item['feature']);
    }

    /** @return list<string> */
    public static function allowedKeys(?User $user): array
    {
        return array_values(array_filter(
            self::keys(),
            fn (string $key) => self::canAccess($user, $key)
        ));
    }

    /** @return list<string> */
    public static function normalizeFooterKeys(?array $keys, ?User $user): array
    {
        $allowed = self::allowedKeys($user);
        $picked = [];
        foreach ($keys ?? [] as $key) {
            $key = (string) $key;
            if (! in_array($key, $allowed, true) || in_array($key, $picked, true)) {
                continue;
            }
            $picked[] = $key;
            if (count($picked) >= self::MAX_FOOTER) {
                break;
            }
        }

        if ($picked === []) {
            foreach (self::DEFAULT_FOOTER as $key) {
                if (in_array($key, $allowed, true)) {
                    $picked[] = $key;
                }
                if (count($picked) >= self::MAX_FOOTER) {
                    break;
                }
            }
        }

        // Always keep at least core items if somehow empty
        if ($picked === []) {
            $picked = array_values(array_intersect(self::CORE, $allowed));
        }

        return $picked;
    }

    /**
     * @return array{
     *   footer: list<array<string, mixed>>,
     *   more: list<array<string, mixed>>,
     *   header: list<array<string, mixed>>
     * }
     */
    public static function resolve(?User $user): array
    {
        $allowed = self::allowedKeys($user);
        $footerKeys = self::normalizeFooterKeys($user?->footer_nav, $user);
        $catalog = self::catalog();

        $mapItem = function (string $key) use ($catalog): array {
            $item = $catalog[$key];

            return [
                'key' => $key,
                'label' => __($item['label']),
                'headerLabel' => __($item['headerLabel'] ?? $item['label']),
                'href' => $item['href'],
                'icon' => $item['icon'],
                'activeKey' => $key === 'dashboard' ? 'dashboard' : $key,
            ];
        };

        $footer = array_map($mapItem, $footerKeys);
        $more = [];
        foreach ($allowed as $key) {
            if (in_array($key, $footerKeys, true)) {
                continue;
            }
            $more[] = $mapItem($key);
        }

        // Web ヘッダーは5件制限なし（利用可能なメニューをすべて表示）
        $header = array_map($mapItem, $allowed);

        return ['footer' => $footer, 'more' => $more, 'header' => $header];
    }
}
