<?php

namespace App\Enums;

enum GroupInvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '承諾待ち',
            self::Accepted => '承諾済み',
            self::Declined => '辞退',
        };
    }
}
