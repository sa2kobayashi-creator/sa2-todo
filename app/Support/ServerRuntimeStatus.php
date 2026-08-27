<?php

namespace App\Support;

/**
 * 起動時にしか読めない .env 項目の現状。設定画面で説明するために使う。
 * TRUSTED_PROXIES は bootstrap/app.php が DB より先に読むので、画面からは変えられない。
 */
class ServerRuntimeStatus
{
    /** @return array<string, mixed> */
    public static function formState(): array
    {
        $proxies = trim((string) env('TRUSTED_PROXIES', '*'));
        $logStack = trim((string) env('LOG_STACK', 'single'));
        $logLevel = trim((string) env('LOG_LEVEL', 'debug'));
        $logChannel = trim((string) env('LOG_CHANNEL', 'stack'));

        $proxyOk = $proxies !== '*';
        $logOk = str_contains($logStack, 'daily');

        return [
            'trusted_proxies' => $proxies === '' ? __('（空＝プロキシを信頼しない）') : $proxies,
            'trusted_proxies_raw' => $proxies,
            'trusted_proxies_ok' => $proxyOk,
            'log_channel' => $logChannel,
            'log_stack' => $logStack === '' ? 'single' : $logStack,
            'log_level' => $logLevel,
            'log_ok' => $logOk,
            'app_url' => (string) config('app.url'),
        ];
    }
}
