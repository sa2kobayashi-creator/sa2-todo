<?php

namespace App\Services;

use App\Exceptions\UsageLimitExceededException;
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
        if ($this->isLlmVoiceFeature($feature)) {
            return max(0, (int) config('usage_limits.llm_voice_requests_per_day', 30));
        }

        return match ($feature) {
            self::FEATURE_TRANSLATE => max(0, (int) config('usage_limits.translate_chars_per_day', 50_000)),
            self::FEATURE_ENHANCE => max(0, (int) config('usage_limits.enhance_requests_per_day', 10)),
            self::FEATURE_WORKERS_AI => max(0, (int) config('usage_limits.workers_ai_requests_per_day', 20)),
            default => 0,
        };
    }

    /**
     * 0 は上限なし。スーパー管理者は運営本人なので生活ガイドを制限しない。
     */
    public function limitForUser(?User $user, string $feature): int
    {
        if ($feature === self::FEATURE_WORKERS_AI && $user?->isSuperAdmin()) {
            return 0;
        }

        return $this->limitFor($feature);
    }

    public function usedToday(User $user, string $feature): int
    {
        if ($this->isLlmVoiceFeature($feature)) {
            return $this->usedTodayLlmVoice($user);
        }

        $row = UserDailyUsage::query()
            ->where('user_id', $user->id)
            ->whereDate('usage_date', now()->toDateString())
            ->where('feature', $feature)
            ->first();

        return (int) ($row?->amount ?? 0);
    }

    public function usedTodayLlmVoice(User $user): int
    {
        return (int) UserDailyUsage::query()
            ->where('user_id', $user->id)
            ->whereDate('usage_date', now()->toDateString())
            ->whereIn('feature', self::llmVoiceFeatures())
            ->sum('amount');
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
        $limit = $this->limitForUser($user, $feature);
        if ($limit <= 0 || $amount === 0) {
            return;
        }

        $used = $this->isLlmVoiceFeature($feature)
            ? $this->usedTodayLlmVoice($user)
            : $this->usedToday($user, $feature);
        if ($used + $amount > $limit) {
            throw new UsageLimitExceededException(
                $feature,
                $limit,
                $used,
                $this->messageFor($feature, $limit, $used)
            );
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

        $this->assertWithin($user, $feature, $amount);

        $today = now()->toDateString();

        DB::transaction(function () use ($user, $feature, $amount, $today) {
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

            $limit = $this->limitForUser($user, $feature);
            if ($limit > 0) {
                $poolUsed = $this->isLlmVoiceFeature($feature)
                    ? $this->usedTodayLlmVoice($user)
                    : (int) $row->amount;
                if ($poolUsed + $amount > $limit) {
                    throw new UsageLimitExceededException(
                        $feature,
                        $limit,
                        $poolUsed,
                        $this->messageFor($feature, $limit, $poolUsed)
                    );
                }
            }

            $row->amount = (int) $row->amount + $amount;
            $row->save();
        });
    }

    private function isLlmVoiceFeature(string $feature): bool
    {
        return in_array($feature, self::llmVoiceFeatures(), true);
    }

    private function messageFor(string $feature, int $limit, int $used): string
    {
        if ($this->isLlmVoiceFeature($feature)) {
            return __('本日の音声AI利用上限（:limit回）に達しました。使用済み: :used', [
                'limit' => $limit,
                'used' => $used,
            ]);
        }

        return match ($feature) {
            self::FEATURE_TRANSLATE => __('本日の翻訳文字数上限（:limit文字）に達しました。使用済み: :used', [
                'limit' => number_format($limit),
                'used' => number_format($used),
            ]),
            self::FEATURE_ENHANCE => __('本日の鮮明化利用上限（:limit回）に達しました。使用済み: :used', [
                'limit' => $limit,
                'used' => $used,
            ]),
            self::FEATURE_WORKERS_AI => __('本日の生活ガイド利用上限（:limit回）に達しました。使用済み: :used', [
                'limit' => $limit,
                'used' => $used,
            ]),
            default => __('本日の利用上限に達しました。'),
        };
    }
}
