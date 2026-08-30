<?php

namespace App\Support;

/**
 * ダッシュボード等のアプリインストール導線（Android APK / iPhone PWA）。
 */
class AppInstall
{
    public static function androidApkUrl(): ?string
    {
        $configured = trim((string) config('app_install.android_apk_url', ''));
        if ($configured !== '' && preg_match('#^https?://#i', $configured) === 1) {
            return $configured;
        }

        $relative = ltrim(trim((string) config('app_install.android_apk_path', 'downloads/sa2-plus.apk')), '/');
        if ($relative === '') {
            return null;
        }

        if (is_file(public_path($relative))) {
            return asset($relative);
        }

        if ($configured !== '' && str_starts_with($configured, '/')) {
            $fromUrl = ltrim($configured, '/');
            if (is_file(public_path($fromUrl))) {
                return asset($fromUrl);
            }
        }

        return null;
    }

    public static function androidApkAvailable(): bool
    {
        return self::androidApkUrl() !== null;
    }
}
