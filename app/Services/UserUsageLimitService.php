<?php

namespace App\Services;

use App\Exceptions\UsageLimitExceededException;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserDailyUsage;
use Illuminate\Support\Facades\DB;

class UserUsageLimitService
{
    public const FEATURE_TRANSLATE = 'translate';

    public const FEATURE_LLM_VOICE = 'llm_voice';

    public const FEATURE_LLM_VOICE_FINANCE = 'llm_voice_finance';

    public const FEATURE_LLM_VOICE_TODO = 'llm_voice_todo';

    public const FEATURE_LLM_VOICE_NOTE = 'llm_voice_note';

    public const FEATURE_ENHANCE = 'enhance';

    public const FEATURE_WORKERS_AI = 'workers_ai';

    public function __construct(private UsageLimitPolicyService $policies) {}

    /** @return list<string> */
    public static function llmVoiceFeatures(): array
    {
        return [
            self::FEATURE_LLM_VOICE,
            self::FEATURE_LLM_VOICE_FINANCE,
            self::FEATURE_LLM_VOICE_TODO,
            self::FEATURE_LLM_VOICE_NOTE,
        ];
    }

    public function limitFor(string $feature): int
    {
        return $this->policies->dailyLimit(null, $this->meterFor($feature));
    }

    /**
     * 0 は上限なし。スーパー管理者は運営本人なので制限しない。
     */
    public function limitForUser(?User $user, string $feature): int
    {
        return $this->policies->dailyLimit($user, $this->meterFor($feature));
    }

    public function monthlyLimitForUser(?User $user, string $feature): int
    {
        return $this->policies->monthlyLimit($user, $this->meterFor($feature));
    }

    public function usedToday(User $user, string $feature): int
    {
        return $this->usedInRange($user, $feature, now()->toDateString(), now()->toDateString());
    }

    public function usedThisMonth(User $user, string $feature): int
    {
        return $this->usedInRange($user, $feature, now()->startOfMonth()->toDateString(), now()->toDateString());
    }

    public function usedTodayLlmVoice(User $user): int
    {
        return $this->usedToday($user, self::FEATURE_LLM_VOICE);
    }

    public function remaining(User $user, string $feature): int
    {
        $limit = $this->limitForUser($user, $feature);
        if ($limit <= 0) {
            return PHP_INT_MAX;
        }

        return max(0, $limit - $this->usedToday($user, $feature));
    }

    /**
     * @throws UsageLimitExceededException
     */
    public function assertWithin(User $user, string $feature, int $amount = 1): void
    {
        $amount = max(0, $amount);
        if ($amount === 0 || $user->isSuperAdmin()) {
            return;
        }

        if ($this->policies->isHardStopped()) {
            throw new UsageLimitExceededException(
                $feature,
                0,
                0,
                __('運営が外部APIの利用を一時停止しています。')
            );
        }

        if ($this->policies->circuitBreakerTripped()) {
            throw new UsageLimitExceededException(
                $feature,
                $this->policies->estimatedMonthlyYenCap(),
                $this->policies->estimatedMonthlyYen(),
                __('運営の今月の見積上限に達しました。')
            );
        }

        $daily = $this->limitForUser($user, $feature);
        if ($daily > 0) {
            $used = $this->usedToday($user, $feature);
            if ($used + $amount > $daily) {
                throw new UsageLimitExceededException(
                    $feature,
                    $daily,
                    $used,
                    $this->messageFor($user, $feature, $daily, $used, 'day')
                );
            }
        }

        $monthly = $this->monthlyLimitForUser($user, $feature);
        if ($monthly > 0) {
            $usedMonth = $this->usedThisMonth($user, $feature);
            if ($usedMonth + $amount > $monthly) {
                throw new UsageLimitExceededException(
                    $feature,
                    $monthly,
                    $usedMonth,
                    $this->messageFor($user, $feature, $monthly, $usedMonth, 'month')
                );
            }
        }
    }

    /**
     * @throws UsageLimitExceededException
     */
    public function consume(User $user, string $feature, int $amount = 1): void
    {
        $amount = max(0, $amount);
        if ($amount === 0) {
            return;
        }

        $today = now()->toDateString();

        DB::transaction(function () use ($user, $feature, $amount, $today) {
            if ($this->policies->usesTenantPool($user) && $user->tenant_id) {
                Tenant::query()->whereKey($user->tenant_id)->lockForUpdate()->first();
            }

            $this->assertWithin($user, $feature, $amount);

            $row = UserDailyUsage::query()
                ->where('user_id', $user->id)
                ->whereDate('usage_date', $today)
                ->where('feature', $feature)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                $row = new UserDailyUsage([
                    'user_id' => $user->id,
                    'usage_date' => $today,
                    'feature' => $feature,
                    'amount' => 0,
                ]);
            }

            $this->assertWithin($user, $feature, $amount);

            $row->amount = (int) $row->amount + $amount;
            $row->save();
        });
    }

    private function usedInRange(User $user, string $feature, string $from, string $to): int
    {
        $ids = $this->policies->poolUserIds($user);
        if ($ids === []) {
            return 0;
        }

        $query = UserDailyUsage::query()
            ->whereIn('user_id', $ids)
            ->whereDate('usage_date', '>=', $from)
            ->whereDate('usage_date', '<=', $to);

        if ($this->isLlmVoiceFeature($feature)) {
            $query->whereIn('feature', self::llmVoiceFeatures());
        } else {
            $query->where('feature', $feature);
        }

        return (int) $query->sum('amount');
    }

    private function meterFor(string $feature): string
    {
        if ($this->isLlmVoiceFeature($feature)) {
            return UsageLimitPolicyService::FEATURE_LLM_VOICE;
        }

        return match ($feature) {
            self::FEATURE_TRANSLATE => UsageLimitPolicyService::FEATURE_TRANSLATE,
            self::FEATURE_ENHANCE => UsageLimitPolicyService::FEATURE_ENHANCE,
            self::FEATURE_WORKERS_AI => UsageLimitPolicyService::FEATURE_WORKERS_AI,
            default => $feature,
        };
    }

    private function isLlmVoiceFeature(string $feature): bool
    {
        return in_array($feature, self::llmVoiceFeatures(), true);
    }

    private function messageFor(User $user, string $feature, int $limit, int $used, string $period): string
    {
        $pool = $this->policies->usesTenantPool($user);
        $when = $period === 'month' ? __('今月') : __('本日');
        $scope = $pool ? __('この契約') : __('このプラン');

        if ($this->isLlmVoiceFeature($feature)) {
            return __(':whenの音声AI利用上限（:scope :limit回）に達しました。使用済み: :used', [
                'when' => $when,
                'scope' => $scope,
                'limit' => $limit,
                'used' => $used,
            ]);
        }

        return match ($feature) {
            self::FEATURE_TRANSLATE => __(':whenの翻訳文字数上限（:scope :limit文字）に達しました。使用済み: :used', [
                'when' => $when,
                'scope' => $scope,
                'limit' => number_format($limit),
                'used' => number_format($used),
            ]),
            self::FEATURE_ENHANCE => __(':whenの鮮明化利用上限（:limit回）に達しました。使用済み: :used', [
                'when' => $when,
                'limit' => $limit,
                'used' => $used,
            ]),
            self::FEATURE_WORKERS_AI => __(':whenの生活ガイド利用上限（:scope :limit回）に達しました。使用済み: :used', [
                'when' => $when,
                'scope' => $scope,
                'limit' => $limit,
                'used' => $used,
            ]),
            default => __('利用上限に達しました。プランの枠です。変更は運営へお問い合わせください。'),
        };
    }
}
