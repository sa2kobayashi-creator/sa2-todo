<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\UsageLimitPolicy;
use App\Models\User;
use App\Models\UserDailyUsage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class UsageLimitPolicyService
{
    public const FEATURE_TRANSLATE = 'translate';

    public const FEATURE_LLM_VOICE = 'llm_voice';

    public const FEATURE_WORKERS_AI = 'workers_ai';

    /** @return list<string> */
    public static function meterFeatures(): array
    {
        return [
            self::FEATURE_TRANSLATE,
            self::FEATURE_LLM_VOICE,
            self::FEATURE_WORKERS_AI,
        ];
    }

    public function suggestedTemplates(): array
    {
        $translateDay = max(0, (int) config('usage_limits.translate_chars_per_day', 50_000));
        $voiceDay = max(0, (int) config('usage_limits.llm_voice_requests_per_day', 30));
        $guideDay = max(0, (int) config('usage_limits.workers_ai_requests_per_day', 20));
        $lightGb = $this->bytesToGb((int) config('photos.user_free_quota_bytes', 50 * 1024 * 1024 * 1024));
        $standardGb = $this->bytesToGb((int) config('photos.standard_quota_bytes', 200 * 1024 * 1024 * 1024));

        return [
            UsageLimitPolicy::PLAN_LIGHT => [
                'storage_quota_gb' => max(1, (int) round($lightGb)),
                'translate_chars_per_day' => 20_000,
                'translate_chars_per_month' => 200_000,
                'llm_voice_requests_per_day' => 10,
                'llm_voice_requests_per_month' => 100,
                'workers_ai_requests_per_day' => 8,
                'workers_ai_requests_per_month' => 80,
            ],
            UsageLimitPolicy::PLAN_STANDARD => [
                'storage_quota_gb' => max(1, (int) round($standardGb)),
                'translate_chars_per_day' => $translateDay,
                'translate_chars_per_month' => $translateDay * 10,
                'llm_voice_requests_per_day' => $voiceDay,
                'llm_voice_requests_per_month' => $voiceDay * 10,
                'workers_ai_requests_per_day' => $guideDay,
                'workers_ai_requests_per_month' => $guideDay * 10,
            ],
            UsageLimitPolicy::PLAN_SPECIAL => [
                'storage_quota_gb' => max(1, (int) round($lightGb)),
                'translate_chars_per_day' => 20_000,
                'translate_chars_per_month' => 200_000,
                'llm_voice_requests_per_day' => 10,
                'llm_voice_requests_per_month' => 100,
                'workers_ai_requests_per_day' => 8,
                'workers_ai_requests_per_month' => 80,
            ],
            UsageLimitPolicy::PLAN_TENANT => [
                'storage_quota_gb' => max(1, (int) round($standardGb)),
                'translate_chars_per_day' => $translateDay,
                'translate_chars_per_month' => $translateDay * 10,
                'llm_voice_requests_per_day' => $voiceDay,
                'llm_voice_requests_per_month' => $voiceDay * 10,
                'workers_ai_requests_per_day' => max($guideDay, 40),
                'workers_ai_requests_per_month' => max($guideDay, 40) * 10,
            ],
        ];
    }

    public function suggestedPlatform(): array
    {
        return [
            'estimated_monthly_yen_cap' => 0,
            'hard_stop_all' => false,
            'yen_per_llm_voice' => max(0, (int) config('usage_limits.yen_per_llm_voice', 5)),
            'yen_per_workers_ai' => max(0, (int) config('usage_limits.yen_per_workers_ai', 3)),
            'yen_per_translate_1000' => max(0, (int) config('usage_limits.yen_per_translate_1000', 2)),
        ];
    }

    public function formState(): array
    {
        $templates = $this->suggestedTemplates();
        foreach (UsageLimitPolicy::templatePlans() as $plan) {
            $templates[$plan] = array_merge($templates[$plan] ?? [], $this->storedLimits($plan));
        }

        return [
            'templates' => $templates,
            'platform' => array_merge($this->suggestedPlatform(), $this->storedLimits(UsageLimitPolicy::PLAN_PLATFORM)),
            'saved' => $this->tableReady() && UsageLimitPolicy::query()->exists(),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $templates
     * @param  array<string, mixed>  $platform
     */
    public function save(array $templates, array $platform): void
    {
        foreach (UsageLimitPolicy::templatePlans() as $plan) {
            $row = is_array($templates[$plan] ?? null) ? $templates[$plan] : [];
            UsageLimitPolicy::query()->updateOrCreate(
                ['plan' => $plan],
                ['limits' => $this->sanitizeTemplate($row)]
            );
        }

        UsageLimitPolicy::query()->updateOrCreate(
            ['plan' => UsageLimitPolicy::PLAN_PLATFORM],
            ['limits' => $this->sanitizePlatform($platform)]
        );
    }

    public function planForUser(?User $user): string
    {
        if ($user === null) {
            return UsageLimitPolicy::PLAN_LIGHT;
        }
        if ($user->tenant_id) {
            return UsageLimitPolicy::PLAN_TENANT;
        }
        if ($user->hasSpecialQuota()) {
            return UsageLimitPolicy::PLAN_SPECIAL;
        }

        $role = $user->roleEnum();
        if ($role === UserRole::Standard || $role->isStaff()) {
            return UsageLimitPolicy::PLAN_STANDARD;
        }

        return $this->hasActiveSubscription($user)
            ? UsageLimitPolicy::PLAN_STANDARD
            : UsageLimitPolicy::PLAN_LIGHT;
    }

    public function usesTenantPool(?User $user): bool
    {
        return $user !== null && $user->tenant_id !== null && ! $user->isSuperAdmin();
    }

    /** @return list<int> */
    public function poolUserIds(?User $user): array
    {
        if (! $this->usesTenantPool($user)) {
            return $user ? [(int) $user->id] : [];
        }

        return User::query()
            ->where('tenant_id', $user->tenant_id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function dailyLimit(?User $user, string $meter): int
    {
        if ($user?->isSuperAdmin()) {
            return 0;
        }

        $key = $this->dailyKey($meter);
        if ($key === '') {
            return $this->configDaily($meter);
        }

        $saved = $this->storedLimits($this->planForUser($user));
        if (array_key_exists($key, $saved)) {
            return max(0, (int) $saved[$key]);
        }

        return $this->configDaily($meter);
    }

    public function monthlyLimit(?User $user, string $meter): int
    {
        if ($user?->isSuperAdmin()) {
            return 0;
        }

        $key = $this->monthlyKey($meter);
        if ($key === '') {
            return 0;
        }

        $saved = $this->storedLimits($this->planForUser($user));
        if (array_key_exists($key, $saved)) {
            return max(0, (int) $saved[$key]);
        }

        return 0;
    }

    /** 保存済みなら GB。なければ null（photos.php の既存ロジックへ）。 */
    public function storageQuotaGb(?User $user): ?int
    {
        if ($user === null) {
            return null;
        }

        $saved = $this->storedLimits($this->planForUser($user));
        if (! array_key_exists('storage_quota_gb', $saved)) {
            return null;
        }

        $gb = (int) $saved['storage_quota_gb'];

        return $gb > 0 ? $gb : null;
    }

    public function storageQuotaBytes(?User $user): ?int
    {
        $gb = $this->storageQuotaGb($user);

        return $gb === null ? null : $gb * 1024 * 1024 * 1024;
    }

    public function platformLimits(): array
    {
        return array_merge($this->suggestedPlatform(), $this->storedLimits(UsageLimitPolicy::PLAN_PLATFORM));
    }

    public function isHardStopped(): bool
    {
        return (bool) ($this->platformLimits()['hard_stop_all'] ?? false);
    }

    public function estimatedMonthlyYenCap(): int
    {
        return max(0, (int) ($this->platformLimits()['estimated_monthly_yen_cap'] ?? 0));
    }

    public function estimatedMonthlyYen(): int
    {
        if (! $this->usageTableReady()) {
            return 0;
        }

        $platform = $this->platformLimits();
        $start = now()->startOfMonth()->toDateString();
        $superIds = User::query()->where('role', UserRole::SuperAdmin->value)->pluck('id')->all();

        $rows = UserDailyUsage::query()
            ->where('usage_date', '>=', $start)
            ->when($superIds !== [], fn ($q) => $q->whereNotIn('user_id', $superIds))
            ->selectRaw('feature, SUM(amount) as amount')
            ->groupBy('feature')
            ->pluck('amount', 'feature');

        $voice = 0;
        foreach (['llm_voice', 'llm_voice_finance', 'llm_voice_todo', 'llm_voice_note'] as $feature) {
            $voice += (int) ($rows[$feature] ?? 0);
        }

        $translate = (int) ($rows['translate'] ?? 0);
        $workers = (int) ($rows['workers_ai'] ?? 0);

        $yen = $voice * max(0, (int) $platform['yen_per_llm_voice']);
        $yen += $workers * max(0, (int) $platform['yen_per_workers_ai']);
        $yen += (int) ceil($translate / 1000) * max(0, (int) $platform['yen_per_translate_1000']);

        return $yen;
    }

    public function circuitBreakerTripped(): bool
    {
        if ($this->isHardStopped()) {
            return true;
        }

        $cap = $this->estimatedMonthlyYenCap();
        if ($cap <= 0) {
            return false;
        }

        return $this->estimatedMonthlyYen() >= $cap;
    }

    public function circuitBreakerRatio(): float
    {
        $cap = $this->estimatedMonthlyYenCap();
        if ($cap <= 0) {
            return 0.0;
        }

        return $this->estimatedMonthlyYen() / $cap;
    }

    /**
     * @return array{
     *   plan: string,
     *   pool: bool,
     *   meters: array<string, array{daily_limit: int, monthly_limit: int, used_today: int, used_month: int}>
     * }
     */
    public function remainingSummary(User $user, UserUsageLimitService $usage): array
    {
        $meters = [];
        foreach ([self::FEATURE_TRANSLATE, self::FEATURE_LLM_VOICE, self::FEATURE_WORKERS_AI] as $meter) {
            $feature = $meter === self::FEATURE_LLM_VOICE ? 'llm_voice' : $meter;
            $meters[$meter] = [
                'daily_limit' => $this->dailyLimit($user, $meter),
                'monthly_limit' => $this->monthlyLimit($user, $meter),
                'used_today' => $usage->usedToday($user, $feature),
                'used_month' => $usage->usedThisMonth($user, $feature),
            ];
        }

        return [
            'plan' => $this->planForUser($user),
            'pool' => $this->usesTenantPool($user),
            'meters' => $meters,
        ];
    }

    /** @return array<string, mixed> */
    public function storedLimits(string $plan): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        $row = UsageLimitPolicy::query()->where('plan', $plan)->first();
        $limits = $row?->limits;

        return is_array($limits) ? $limits : [];
    }

    /** @param  array<string, mixed>  $row */
    private function sanitizeTemplate(array $row): array
    {
        $keys = [
            'storage_quota_gb',
            'translate_chars_per_day',
            'translate_chars_per_month',
            'llm_voice_requests_per_day',
            'llm_voice_requests_per_month',
            'workers_ai_requests_per_day',
            'workers_ai_requests_per_month',
        ];
        $clean = [];
        foreach ($keys as $key) {
            $clean[$key] = max(0, (int) ($row[$key] ?? 0));
        }

        return $clean;
    }

    /** @param  array<string, mixed>  $row */
    private function sanitizePlatform(array $row): array
    {
        return [
            'estimated_monthly_yen_cap' => max(0, (int) ($row['estimated_monthly_yen_cap'] ?? 0)),
            'hard_stop_all' => filter_var($row['hard_stop_all'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'yen_per_llm_voice' => max(0, (int) ($row['yen_per_llm_voice'] ?? config('usage_limits.yen_per_llm_voice', 5))),
            'yen_per_workers_ai' => max(0, (int) ($row['yen_per_workers_ai'] ?? config('usage_limits.yen_per_workers_ai', 3))),
            'yen_per_translate_1000' => max(0, (int) ($row['yen_per_translate_1000'] ?? config('usage_limits.yen_per_translate_1000', 2))),
        ];
    }

    private function dailyKey(string $meter): string
    {
        return match ($meter) {
            self::FEATURE_TRANSLATE => 'translate_chars_per_day',
            self::FEATURE_LLM_VOICE => 'llm_voice_requests_per_day',
            self::FEATURE_WORKERS_AI => 'workers_ai_requests_per_day',
            default => '',
        };
    }

    private function monthlyKey(string $meter): string
    {
        return match ($meter) {
            self::FEATURE_TRANSLATE => 'translate_chars_per_month',
            self::FEATURE_LLM_VOICE => 'llm_voice_requests_per_month',
            self::FEATURE_WORKERS_AI => 'workers_ai_requests_per_month',
            default => '',
        };
    }

    private function configDaily(string $meter): int
    {
        return match ($meter) {
            self::FEATURE_TRANSLATE => max(0, (int) config('usage_limits.translate_chars_per_day', 50_000)),
            self::FEATURE_LLM_VOICE => max(0, (int) config('usage_limits.llm_voice_requests_per_day', 30)),
            self::FEATURE_WORKERS_AI => max(0, (int) config('usage_limits.workers_ai_requests_per_day', 20)),
            default => 0,
        };
    }

    private function hasActiveSubscription(User $user): bool
    {
        $raw = $user->subscription_status;
        $status = $raw instanceof SubscriptionStatus
            ? $raw
            : (SubscriptionStatus::tryFrom((string) $raw) ?? SubscriptionStatus::None);

        if ($status === SubscriptionStatus::Active) {
            return true;
        }
        if ($status !== SubscriptionStatus::Trial) {
            return false;
        }
        $ends = $user->trial_ends_at;
        if ($ends === null) {
            return true;
        }

        return Carbon::parse($ends)->isFuture();
    }

    private function bytesToGb(int $bytes): float
    {
        if ($bytes <= 0) {
            return 50;
        }

        return $bytes / (1024 * 1024 * 1024);
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('usage_limit_policies');
        } catch (\Throwable) {
            return false;
        }
    }

    private function usageTableReady(): bool
    {
        try {
            return Schema::hasTable('user_daily_usages');
        } catch (\Throwable) {
            return false;
        }
    }
}
