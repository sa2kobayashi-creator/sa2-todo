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

    public function label(): string
    {
        return match ($this) {
            self::Finance => '入出金経費',
            self::Transit => '路線検索',
            self::Travel => 'Travel',
            self::Map => 'マップ',
            self::Music => '音楽',
            self::Video => '動画',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $feature) => $feature->value, self::cases());
    }

    /** @return list<self> */
    public static function assignable(): array
    {
        return self::cases();
    }

    /** @return list<string> */
    public static function defaultsForRole(UserRole $role): array
    {
        return array_values(array_intersect($role->features(), self::values()));
    }
}
