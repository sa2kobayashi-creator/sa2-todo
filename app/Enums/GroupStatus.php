<?php

namespace App\Enums;

enum GroupStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '承認待ち',
            self::Approved => '承認済み',
            self::Rejected => '却下',
        };
    }
}
