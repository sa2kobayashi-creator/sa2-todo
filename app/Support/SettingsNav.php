<?php

namespace App\Support;

use App\Models\User;

class SettingsNav
{
    /**
     * 設定メニュー「初期設定」内のタブ。
     *
     * @return array<string, string> section => ラベル
     */
    public static function setupTabs(?User $user = null): array
    {
        $tabs = [
            'holidays' => '休日設定',
            'ai' => 'AI設定',
            'storage' => 'ストレージ設定',
            'enhance' => 'API設定',
            'integration' => '外部連携',
            'notifications' => '通知設定',
        ];
        if ($user?->isSuperAdmin()) {
            $tabs['sales'] = '公開販売';
        }

        return $tabs;
    }

    public static function isSetupSection(?string $section): bool
    {
        return is_string($section) && array_key_exists($section, self::setupTabs());
    }

    public static function setupMenuHref(?string $section): string
    {
        return self::isSetupSection($section)
            ? self::tabHref($section)
            : self::tabHref('holidays');
    }

    public static function tabHref(string $section): string
    {
        if ($section === 'ai') {
            return '/settings?section=ai&tab=translation';
        }

        return '/settings?section='.$section;
    }
}
