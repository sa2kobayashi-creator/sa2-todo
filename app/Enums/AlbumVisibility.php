<?php

namespace App\Enums;

enum AlbumVisibility: string
{
    case Private = 'private';
    case Group = 'group';
    case Public = 'public';

    public function label(): string
    {
        return match ($this) {
            self::Private => '非公開',
            self::Group => 'グループのみ',
            self::Public => '登録ユーザーに公開',
        };
    }
}
