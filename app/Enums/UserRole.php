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
            self::SuperAdmin => 'すべてのメニューに加え、招待コードなど運営設定を変更できます。鮮明化・翻訳・LLM 音声はスーパー管理者向けの試作機能です。',
            self::Admin => '設定・ユーザー管理を含むすべてのメニューを利用できます。招待コードの変更と鮮明化・翻訳・LLM 音声はできません。',
            self::Standard => '設定以外の基本メニュー（Travel は既定オフ）。グループの作成もできます。追加メニューはユーザー／グループ設定で調整できます。',
            self::Light => 'ダッシュボード、Todo、メモ、Photos、メッセージ、マイページが基本。グループの作成はできません。追加メニューはユーザー／グループ設定で付与できます。',
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
