<?php

namespace App\Support;

/**
 * サーバー側からユーザー指定 URL を取得する前の安全確認（SSRF 対策）。
 *
 * 許可するのは http/https かつ、名前解決した結果がグローバル IP のホストだけ。
 * これを通さないと、社内サービスやクラウドのメタデータエンドポイント
 * （169.254.169.254 など）をアプリ経由で読み出されてしまう。
 */
class PublicUrlGuard
{
    /** @throws \InvalidArgumentException */
    public static function assertFetchable(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \InvalidArgumentException(__('URL の形式が正しくありません。'));
        }

        $ips = self::resolve($host);
        if ($ips === []) {
            throw new \InvalidArgumentException(__('URL のホストを解決できませんでした。'));
        }

        foreach ($ips as $ip) {
            if (! self::isGlobal($ip)) {
                throw new \InvalidArgumentException(__('この URL は取得できません。'));
            }
        }
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $record) {
            foreach (['ip', 'ipv6'] as $key) {
                if (! empty($record[$key])) {
                    $ips[] = (string) $record[$key];
                }
            }
        }

        if ($ips === []) {
            $ips = (array) @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique(array_filter($ips)));
    }

    private static function isGlobal(string $ip): bool
    {
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
