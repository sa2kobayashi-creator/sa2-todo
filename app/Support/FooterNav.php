<?php

namespace App\Support;

use App\Models\User;

class FooterNav
{
    /** Always available (no feature gate). */
    public const CORE = ['dashboard', 'todos', 'notes', 'photos'];

    /** Default visible footer slots (order matters). */
    public const DEFAULT_FOOTER = ['dashboard', 'todos', 'notes', 'photos', 'messages'];

    /** One row of the smartphone bottom bar. */
    public const FOOTER_PER_ROW = 5;

    /** Max rows when the bar is expanded (11+ items). */
    public const MAX_FOOTER_ROWS = 3;

    /** Max items in the smartphone bottom bar (5 per row × 3 rows). */
    public const MAX_FOOTER = 15;

    /** Max items in the web header (catalog size; effectively unlimited for current set). */
    public const MAX_HEADER = 20;

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
            'mail' => ['label' => 'メール', 'headerLabel' => 'メール', 'href' => '/mail', 'icon' => '✉', 'feature' => 'mail'],
            'finance' => ['label' => '家計簿', 'headerLabel' => '家計簿', 'href' => '/finance', 'icon' => '💰', 'feature' => 'finance'],
            'music' => ['label' => '音楽', 'headerLabel' => '音楽', 'href' => '/music', 'icon' => '♪', 'feature' => 'music'],
            'video' => ['label' => '動画', 'headerLabel' => '動画', 'href' => '/video', 'icon' => '▶', 'feature' => 'video'],
            'translate' => ['label' => '翻訳', 'headerLabel' => '翻訳', 'href' => '/translate', 'icon' => '文A', 'feature' => 'translate'],
            'guide' => ['label' => 'ガイド', 'headerLabel' => '生活ガイド', 'href' => '/guide', 'icon' => '💡', 'feature' => 'guide'],
            'transit' => ['label' => '路線', 'headerLabel' => '路線検索', 'href' => '/transit', 'icon' => '🚌', 'feature' => 'transit'],
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

    /**
     * 未設定（null）時、および昔の既定5件だけが保存されている場合は、
     * 利用可能メニューをすべて下部に載せる（Webヘッダーと同じ）。
     * 6件以上なら長押しで2〜3段に展開できる。
     *
     * @return list<string>
     */
    public static function normalizeFooterKeys(?array $keys, ?User $user): array
    {
        $allowed = self::allowedKeys($user);

        if ($keys === null || self::isLegacyDefaultFooterSelection($keys, $allowed)) {
            return self::defaultFooterKeysForAllowed($allowed);
        }

        $picked = [];
        foreach ($keys as $key) {
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
            return self::defaultFooterKeysForAllowed($allowed);
        }

        return $picked;
    }

    /**
     * 並べ替えだけで既定5件が JSON 保存されたケースを「未設定」扱いする。
     *
     * @param  list<string>|null  $keys
     * @param  list<string>  $allowed
     */
    private static function isLegacyDefaultFooterSelection(?array $keys, array $allowed): bool
    {
        if ($keys === null) {
            return true;
        }

        $legacy = [];
        foreach (self::DEFAULT_FOOTER as $key) {
            if (in_array($key, $allowed, true)) {
                $legacy[] = $key;
            }
        }

        return $legacy !== [] && self::sameKeySet($keys, $legacy);
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private static function defaultFooterKeysForAllowed(array $allowed): array
    {
        $ordered = [];
        foreach (array_merge(self::DEFAULT_FOOTER, $allowed) as $key) {
            if (! in_array($key, $allowed, true) || in_array($key, $ordered, true)) {
                continue;
            }
            $ordered[] = $key;
            if (count($ordered) >= self::MAX_FOOTER) {
                break;
            }
        }

        if ($ordered === []) {
            return array_values(array_intersect(self::CORE, $allowed));
        }

        return $ordered;
    }

    /** Collapsed bar is always 1 row. Expanded rows: 6–10 → 2, 11+ → 3. */
    public static function footerExpandedRows(int $count): int
    {
        if ($count <= self::FOOTER_PER_ROW) {
            return 1;
        }
        if ($count <= self::FOOTER_PER_ROW * 2) {
            return 2;
        }

        return self::MAX_FOOTER_ROWS;
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    public static function sameKeySet(array $left, array $right): bool
    {
        if (count($left) !== count($right)) {
            return false;
        }
        $a = $left;
        $b = $right;
        sort($a);
        sort($b);

        return $a === $b;
    }

    /**
     * Web ヘッダー用。null / 空のときは利用可能メニューをすべて表示（従来互換）。
     *
     * @return list<string>
     */
    public static function normalizeHeaderKeys(?array $keys, ?User $user): array
    {
        $allowed = self::allowedKeys($user);

        // 未設定時は全表示（導入前と同じ挙動）
        if ($keys === null) {
            return $allowed;
        }

        $picked = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            if (! in_array($key, $allowed, true) || in_array($key, $picked, true)) {
                continue;
            }
            $picked[] = $key;
            if (count($picked) >= self::MAX_HEADER) {
                break;
            }
        }

        // 明示的に0件保存された場合も、最低コアは残す
        if ($picked === []) {
            foreach (self::CORE as $key) {
                if (in_array($key, $allowed, true)) {
                    $picked[] = $key;
                }
            }
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
        $headerKeys = self::normalizeHeaderKeys($user?->header_nav, $user);
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

        $header = array_map($mapItem, $headerKeys);

        return ['footer' => $footer, 'more' => $more, 'header' => $header];
    }
}
