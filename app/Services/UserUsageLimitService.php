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

    public const FEATURE_ENHANCE = 'enhance';

    public function limitFor(string $feature): int
    {
        return match ($feature) {
            self::FEATURE_TRANSLATE => max(0, (int) config('usage_limits.translate_chars_per_day', 50_000)),
            self::FEATURE_LLM_VOICE => max(0, (int) config('usage_limits.llm_voice_requests_per_day', 30)),
            self::FEATURE_ENHANCE => max(0, (int) config('usage_limits.enhance_requests_per_day', 10)),
            default => 0,
        };
    }

    public function usedToday(User $user, string $feature): int
    {
        $row = UserDailyUsage::query()
            ->where('user_id', $user->id)
            ->whereDate('usage_date', now()->toDateString())
            ->where('feature', $feature)
            ->first();

        return (int) ($row?->amount ?? 0);
    }

    public function remaining(User $user, string $feature): int
    {
        $limit = $this->limitFor($feature);
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
        $limit = $this->limitFor($feature);
        if ($limit <= 0 || $amount === 0) {
            return;
        }

        $used = $this->usedToday($user, $feature);
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

            $limit = $this->limitFor($feature);
            if ($limit > 0 && ((int) $row->amount + $amount) > $limit) {
                throw new UsageLimitExceededException(
                    $feature,
                    $limit,
                    (int) $row->amount,
                    $this->messageFor($feature, $limit, (int) $row->amount)
                );
            }

            $row->amount = (int) $row->amount + $amount;
            $row->save();
        });
    }

    private function messageFor(string $feature, int $limit, int $used): string
    {
        return match ($feature) {
            self::FEATURE_TRANSLATE => __('本日の翻訳文字数上限（:limit文字）に達しました。使用済み: :used', [
                'limit' => number_format($limit),
                'used' => number_format($used),
            ]),
            self::FEATURE_LLM_VOICE => __('本日の音声AI利用上限（:limit回）に達しました。使用済み: :used', [
                'limit' => $limit,
                'used' => $used,
            ]),
            self::FEATURE_ENHANCE => __('本日の鮮明化利用上限（:limit回）に達しました。使用済み: :used', [
                'limit' => $limit,
                'used' => $used,
            ]),
            default => __('本日の利用上限に達しました。'),
        };
    }
}
