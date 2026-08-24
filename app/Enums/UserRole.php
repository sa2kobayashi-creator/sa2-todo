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
            self::SuperAdmin => '運営者',
            self::Admin => '管理者',
            self::Standard => 'スタンダード',
            self::Light => 'ライト',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'このアプリの運営を行っております。お問い合わせは、お問い合わせメニューから行って下さい。',
            self::Admin => '契約代表1名。ユーザー・休日・ストレージ／AI／API を設定できます。共有のテナント契約は自分の契約の中だけ（月5名まで・メール各1込み）。招待コード・LINE／Web Push は運営専用です。写真鮮明化は試作のため利用できません。',
            self::Standard => '設定以外の基本メニュー（翻訳あり、Travel は既定オフ）。グループの作成もできます。追加メニューはユーザー／グループ設定で調整できます。',
            self::Light => 'ダッシュボード、Todo、メモ、Photos、メッセージ、翻訳、マイページが基本。グループの作成はできません。追加メニューはユーザー／グループ設定で付与できます。',
        };
    }

    /**
     * Standard 向け MenuFeature 既定から Travel を外す前の一覧。
     * 既存ユーザー退避用（migration とテストで共有）。
     *
     * @return list<string>
     */
    public static function legacyStandardMenuFeatures(): array
    {
        return [
            'finance',
            'transit',
            'travel',
            'map',
            'music',
            'video',
            'messages',
            'mail',
        ];
    }

    public function isStaff(): bool
    {
        return $this === self::SuperAdmin || $this === self::Admin;
    }

    /** @return list<string> */
    public function features(): array
    {
        return match ($this) {
            self::SuperAdmin => [
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
                'mail',
                'translate',
                'groups',
                'settings',
                'admin',
                'mypage',
            ],
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
                'mail',
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
                // travel は Travelpayouts 非公開方針のため Standard 既定から外す（管理画面で個別付与可）
                'map',
                'music',
                'video',
                'messages',
                'mail',
                'translate',
                'groups',
                'mypage',
            ],
            self::Light => [
                'dashboard',
                'todos',
                'notes',
                'photos',
                'messages',
                'mail',
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
