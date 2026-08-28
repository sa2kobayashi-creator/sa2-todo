<?php

namespace App\Enums;

enum RegistrationApplicationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Completed = 'completed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '申請中',
            self::Approved => '承認済み（登録待ち）',
            self::Rejected => '却下',
            self::Completed => '登録完了',
            self::Expired => '期限切れ',
        };
    }
}
