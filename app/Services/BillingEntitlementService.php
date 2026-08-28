<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\User;
use Carbon\Carbon;

/**
 * 課金エンタイトルメントの判定口。
 *
 * 写真超過・メールボックス・（将来）Standard 機能は、すべてここを経由する。
 * Stripe 導入時は Webhook が users のフラグを更新し、判定ロジックはこのまま使う。
 */
class BillingEntitlementService
{
    public function status(User $user): SubscriptionStatus
    {
        $raw = $user->subscription_status;
        if ($raw instanceof SubscriptionStatus) {
            return $raw;
        }

        return SubscriptionStatus::tryFrom((string) $raw) ?? SubscriptionStatus::None;
    }

    /**
     * 有料プラン（お試し・支払い遅延の猶予中を含む）がいま有効か。
     * お試しは trial_ends_at が未来、または未設定（手動運用）のとき有効。
     * past_due は設定の猶予日数（既定7日）を過ぎるまで有効扱い。
     */
    public function hasActiveSubscription(User $user): bool
    {
        $status = $this->status($user);

        if ($status === SubscriptionStatus::Active) {
            return true;
        }

        if ($status === SubscriptionStatus::Trial) {
            $ends = $user->trial_ends_at;
            if ($ends === null) {
                return true;
            }

            return Carbon::parse($ends)->isFuture();
        }

        if ($status === SubscriptionStatus::PastDue) {
            return ! $this->pastDueGraceExpired($user);
        }

        return false;
    }

    /**
     * 支払い遅延の検知から猶予日数を過ぎたか。
     * 専用カラムがないため、契約状態が past_due になった時点の updated_at を起点にする。
     */
    public function pastDueGraceExpired(User $user, ?Carbon $since = null): bool
    {
        if ($this->status($user) !== SubscriptionStatus::PastDue) {
            return false;
        }

        $grace = max(0, (int) config('billing.past_due_grace_days', 7));
        $from = $since ?? Carbon::parse($user->updated_at ?? now());

        return $from->copy()->addDays($grace)->isPast();
    }

    /**
     * 無料枠超過のアップロードを許可するか（ユーザー単位）。
     * 運営のストレージ設定「有料枠を許可」は PhotoService 側で別途見る。
     */
    public function allowsPaidStorageOverage(User $user): bool
    {
        if (! (bool) config('photos.paid_overage_enabled', false)) {
            return false;
        }

        return (bool) $user->storage_overage_active;
    }

    /** @sa2-plus.com メールボックスの有料オプションが有効か */
    public function hasMailboxAddon(User $user): bool
    {
        return (bool) $user->mailbox_addon_active;
    }

    /**
     * 製品上の無料枠バイト数。
     * Light / 未契約: config の 20GB。Standard（ロール）およびスタッフ: 200GB。
     */
    public function storageFreeQuotaBytes(User $user): int
    {
        $saved = app(UsageLimitPolicyService::class)->storageQuotaBytes($user);
        if ($saved !== null) {
            return $saved;
        }

        $base = max(1, (int) config('photos.user_free_quota_bytes', 20 * 1024 * 1024 * 1024));
        $standard = max($base, (int) config('photos.standard_quota_bytes', 200 * 1024 * 1024 * 1024));

        if ($this->qualifiesForStandardStorageQuota($user)) {
            return $standard;
        }

        return $base;
    }

    /**
     * Standard ロール／スタッフは 200GB 枠。
     * Light でも有料契約（請求書運用など）があれば Standard 枠。
     */
    public function qualifiesForStandardStorageQuota(User $user): bool
    {
        $role = $user->roleEnum();
        if ($role->isStaff() || $role === UserRole::Standard) {
            return true;
        }

        return $this->hasActiveSubscription($user);
    }

    /**
     * 管理画面・Stripe から契約状態を一括更新する。
     *
     * @param  array{
     *   subscription_status?: string|SubscriptionStatus,
     *   trial_ends_at?: \DateTimeInterface|string|null,
     *   storage_overage_active?: bool,
     *   mailbox_addon_active?: bool
     * }  $attrs
     */
    public function apply(User $user, array $attrs): User
    {
        if (array_key_exists('subscription_status', $attrs)) {
            $status = $attrs['subscription_status'];
            if (! $status instanceof SubscriptionStatus) {
                $status = SubscriptionStatus::tryFrom((string) $status) ?? SubscriptionStatus::None;
            }
            $user->subscription_status = $status;
        }

        if (array_key_exists('trial_ends_at', $attrs)) {
            $ends = $attrs['trial_ends_at'];
            $user->trial_ends_at = $ends === null || $ends === ''
                ? null
                : Carbon::parse($ends);
        }

        if (array_key_exists('storage_overage_active', $attrs)) {
            $user->storage_overage_active = (bool) $attrs['storage_overage_active'];
        }

        if (array_key_exists('mailbox_addon_active', $attrs)) {
            $user->mailbox_addon_active = (bool) $attrs['mailbox_addon_active'];
        }

        $user->save();

        return $user->refresh();
    }
}
