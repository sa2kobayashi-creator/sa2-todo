<?php

namespace App\Support;

/**
 * ダッシュボード等のアプリインストール導線（Android APK / iPhone PWA）。
 */
class AppInstall
{
    public static function localRelativePath(): string
    {
        return ltrim(trim((string) config('app_install.android_apk_path', 'downloads/sa2-plus.apk')), '/');
    }

    public static function localFileExists(): bool
    {
        $relative = self::localRelativePath();

        return $relative !== '' && is_file(public_path($relative));
    }

    public static function localAbsolutePath(): ?string
    {
        if (! self::localFileExists()) {
            return null;
        }

        return public_path(self::localRelativePath());
    }

    /**
     * 公開ダウンロードURL。優先順:
     * 1) 外部ホストの配布URL（設定）
     * 2) ローカルに置いた APK（public/downloads/…）
     * 3) 同一オリジンの設定URLで、実ファイルが存在する場合
     */
    public static function androidApkUrl(): ?string
    {
        $configured = trim((string) config('app_install.android_apk_url', ''));

        if ($configured !== '' && preg_match('#^https?://#i', $configured) === 1) {
            if (self::isExternalHttpUrl($configured)) {
                return $configured;
            }
            // 同一サイトの URL はファイルがあるときだけ採用（無いと 404 になる）
            if (self::sameOriginPathExists($configured)) {
                return $configured;
            }
        }

        if (self::localFileExists()) {
            // 短い /sa2-plus.apk でもルート配信する（実体は downloads/）
            return url('/sa2-plus.apk').self::cacheBuster(self::localRelativePath());
        }

        if ($configured !== '' && str_starts_with($configured, '/')) {
            $fromUrl = ltrim($configured, '/');
            if (is_file(public_path($fromUrl))) {
                return asset($fromUrl).self::cacheBuster($fromUrl);
            }
        }

        return null;
    }

    public static function androidApkAvailable(): bool
    {
        return self::androidApkUrl() !== null;
    }

    private static function isExternalHttpUrl(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return false;
        }

        $appHost = strtolower((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?? ''));
        if ($appHost === '') {
            return true;
        }

        return $host !== $appHost && $host !== 'www.'.$appHost && 'www.'.$host !== $appHost;
    }

    private static function sameOriginPathExists(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $path = ltrim($path, '/');
        if ($path === '') {
            return false;
        }

        if (is_file(public_path($path))) {
            return true;
        }

        // /sa2-plus.apk → downloads/sa2-plus.apk
        if ($path === 'sa2-plus.apk' && self::localFileExists()) {
            return true;
        }

        return false;
    }

    private static function cacheBuster(string $relative): string
    {
        $path = public_path($relative);
        $mtime = @filemtime($path);

        return $mtime ? ('?v='.$mtime) : '';
    }
}
