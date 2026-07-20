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
            self::Standard => '設定以外のメニューを表示・変更できます。',
            self::Light => 'ダッシュボード、Todo、メモ、Photos のみ利用できます。',
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
                'map',
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
                'map',
                'mypage',
            ],
            self::Light => [
                'dashboard',
                'todos',
                'notes',
                'photos',
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
