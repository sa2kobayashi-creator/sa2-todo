<?php

namespace App\Enums;

/**
 * ユーザーの契約状態（権限ロールとは別）。
 * Stripe 接続前は管理画面から手動で切り替える。
 */
enum SubscriptionStatus: string
{
    case None = 'none';
    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::None => '未契約',
            self::Trial => 'お試し中',
            self::Active => '有効',
            self::PastDue => '支払い遅延',
            self::Canceled => '解約済み',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::None => '有料プラン未契約。Light 相当の無料枠のみ。',
            self::Trial => 'お試し期間中。期限切れ後は未契約扱い。',
            self::Active => '有料プランが有効。',
            self::PastDue => '支払い遅延。猶予後は機能制限の対象。',
            self::Canceled => '解約済み。期限到来後は未契約扱い。',
        };
    }

    /**
     * 有料機能を開いてよい状態か（お試し・支払い遅延の猶予中を含む）。
     * past_due は即剥奪せず、猶予切れは BillingEntitlementService 側で見る。
     */
    public function grantsPaidAccess(): bool
    {
        return $this === self::Trial
            || $this === self::Active
            || $this === self::PastDue;
    }

    /** @return list<self> */
    public static function assignable(): array
    {
        return [self::None, self::Trial, self::Active, self::PastDue, self::Canceled];
    }
}
