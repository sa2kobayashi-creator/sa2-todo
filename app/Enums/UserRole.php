<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Standard = 'standard';
    case Light = 'light';

    public function label(): string
    {
        return match ($this) {
            self::Admin => '管理者',
            self::Standard => 'スタンダード',
            self::Light => 'ライト',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'すべてのメニューを表示・編集できます。',
            self::Standard => '設定以外の基本メニュー。グループの作成もできます。追加メニューはユーザー／グループ設定で調整できます。',
            self::Light => 'ダッシュボード、Todo、メモ、Photos、マイページが基本。グループの作成はできません。追加メニューはユーザー／グループ設定で付与できます。',
        };
    }

    /** @return list<string> */
    public function features(): array
    {
        return match ($this) {
            self::Admin => [
                'dashboard',
                'todos',
                'notes',
                'photos',
                'finance',
                'transit',
                'travel',
                'map',
                'music',
                'video',
                'messages',
                'translate',
                'groups',
                'settings',
                'admin',
                'mypage',
            ],
            self::Standard => [
                'dashboard',
                'todos',
                'notes',
                'photos',
                'finance',
                'transit',
                'travel',
                'map',
                'music',
                'video',
                'messages',
                'translate',
                'groups',
                'mypage',
            ],
            self::Light => [
                'dashboard',
                'todos',
                'notes',
                'photos',
                'music',
                'video',
                'translate',
                'mypage',
            ],
        };
    }

    public function canAccess(string $feature): bool
    {
        return in_array($feature, $this->features(), true);
    }

    /** @return list<self> */
    public static function assignable(): array
    {
        return [self::Admin, self::Standard, self::Light];
    }
}
