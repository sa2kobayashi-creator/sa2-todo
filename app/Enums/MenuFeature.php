<?php

namespace App\Enums;

enum MenuFeature: string
{
    case Finance = 'finance';
    case Transit = 'transit';
    case Travel = 'travel';
    case Map = 'map';
    case Music = 'music';
    case Video = 'video';
    case Messages = 'messages';
    case Mail = 'mail';
    case Translate = 'translate';
    case Guide = 'guide';

    public function label(): string
    {
        return match ($this) {
            self::Finance => '家計簿',
            self::Transit => '路線検索',
            self::Travel => '航空',
            self::Map => 'マップ',
            self::Music => '音楽',
            self::Video => '動画',
            self::Messages => 'メッセージ',
            self::Mail => 'メール',
            self::Translate => '翻訳',
            self::Guide => '生活ガイド',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $feature) => $feature->value, self::cases());
    }

    public function operatorOnly(): bool
    {
        return $this === self::Travel;
    }

    /** ユーザー／グループへ付与できるメニュー（運営専用は除く） */
    /** @return list<self> */
    public static function assignable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $feature) => ! $feature->operatorOnly()
        ));
    }

    /** @return list<string> */
    public static function assignableValues(): array
    {
        return array_map(fn (self $feature) => $feature->value, self::assignable());
    }

    /** @return list<string> */
    public static function defaultsForRole(UserRole $role): array
    {
        return array_values(array_intersect($role->features(), self::values()));
    }
}
