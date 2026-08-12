<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Standard = 'standard';
    case Light = 'light';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'スーパー管理者',
            self::Admin => '管理者',
            self::Standard => 'スタンダード',
            self::Light => 'ライト',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'すべてのメニューに加え、招待コードなど運営設定を変更できます。',
            self::Admin => '設定・ユーザー管理を含むすべてのメニューを利用できます。招待コードの変更はできません。',
            self::Standard => '設定以外の基本メニュー。グループの作成もできます。追加メニューはユーザー／グループ設定で調整できます。',
            self::Light => 'ダッシュボード、Todo、メモ、Photos、マイページが基本。グループの作成はできません。追加メニューはユーザー／グループ設定で付与できます。',
        };
    }

    public function isStaff(): bool
    {
        return $this === self::SuperAdmin || $this === self::Admin;
    }

    /** @return list<string> */
    public function features(): array
    {
        return match ($this) {
            self::SuperAdmin, self::Admin => [
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

    /** 画面の権限説明一覧用 */
    /** @return list<self> */
    public static function assignable(): array
    {
        return [self::SuperAdmin, self::Admin, self::Standard, self::Light];
    }

    /** 操作者が付与できる権限 */
    /** @return list<self> */
    public static function assignableBy(self $actor): array
    {
        return match ($actor) {
            self::SuperAdmin => [self::SuperAdmin, self::Admin, self::Standard, self::Light],
            self::Admin => [self::Admin, self::Standard, self::Light],
            default => [self::Standard, self::Light],
        };
    }
}
