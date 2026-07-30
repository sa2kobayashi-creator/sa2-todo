<?php

namespace App\Enums;

enum AppContext: string
{
    case Personal = 'personal';
    case Work = 'work';

    public function label(): string
    {
        return match ($this) {
            self::Personal => __('プライベート'),
            self::Work => __('仕事'),
        };
    }

    public static function tryFromInput(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Personal;
    }
}
