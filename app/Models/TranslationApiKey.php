<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class TranslationApiKey extends Model
{
    protected $fillable = [
        'name',
        'api_key',
        'provider',
        'api_url',
        'daily_limit',
        'monthly_limit',
        'current_daily_usage',
        'current_monthly_usage',
        'last_reset_date',
        'last_monthly_reset_date',
        'error_count',
        'last_error_at',
        'is_active',
        'priority',
        'notes',
        'deepl_character_count',
        'deepl_character_limit',
        'deepl_usage_fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'daily_limit' => 'integer',
            'monthly_limit' => 'integer',
            'current_daily_usage' => 'integer',
            'current_monthly_usage' => 'integer',
            'last_reset_date' => 'date',
            'last_monthly_reset_date' => 'date',
            'error_count' => 'integer',
            'last_error_at' => 'datetime',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'deepl_character_count' => 'integer',
            'deepl_character_limit' => 'integer',
            'deepl_usage_fetched_at' => 'datetime',
        ];
    }

    /**
     * 使用可能なAPIキーを優先順位・使用量順に取得する。
     *
     * @return Collection<int, TranslationApiKey>
     */
    public static function getAvailableKeys(string $provider = 'deepl'): Collection
    {
        return static::query()
            ->where('is_active', true)
            ->where('provider', $provider)
            ->where(function ($query) {
                $query->whereNull('daily_limit')
                    ->orWhereColumn('current_daily_usage', '<', 'daily_limit');
            })
            ->where(function ($query) {
                $query->whereNull('monthly_limit')
                    ->orWhereColumn('current_monthly_usage', '<', 'monthly_limit');
            })
            ->where(function ($query) {
                // エラー5回以上は除外（30分後に再試行可能）
                $query->where('error_count', '<', 5)
                    ->orWhere('last_error_at', '<', now()->subMinutes(30));
            })
            ->orderBy('priority', 'desc')
            ->orderBy('current_daily_usage', 'asc')
            ->get();
    }

    /**
     * 日次・月次の使用量をリセットが必要なら初期化する。
     */
    public function resetUsageIfNeeded(): void
    {
        $today = now()->toDateString();
        $dirty = false;

        if ($this->last_reset_date?->toDateString() !== $today) {
            $this->current_daily_usage = 0;
            $this->last_reset_date = $today;
            $dirty = true;
        }

        $thisMonth = now()->format('Y-m');
        if ($this->last_monthly_reset_date?->format('Y-m') !== $thisMonth) {
            $this->current_monthly_usage = 0;
            $this->last_monthly_reset_date = $today;
            $dirty = true;
        }

        if ($dirty) {
            $this->save();
        }
    }

    public function incrementUsage(int $characters): void
    {
        $this->resetUsageIfNeeded();
        $this->increment('current_daily_usage', $characters);
        $this->increment('current_monthly_usage', $characters);
    }

    public function recordError(): void
    {
        $this->increment('error_count');
        $this->last_error_at = now();
        $this->save();
    }

    public function resetErrorCount(): void
    {
        if ($this->error_count > 0) {
            $this->error_count = 0;
            $this->last_error_at = null;
            $this->save();
        }
    }

    public function getDailyUsageRate(): ?float
    {
        if (! $this->daily_limit) {
            return null;
        }

        return ($this->current_daily_usage / $this->daily_limit) * 100;
    }

    public function getMonthlyUsageRate(): ?float
    {
        if (! $this->monthly_limit) {
            return null;
        }

        return ($this->current_monthly_usage / $this->monthly_limit) * 100;
    }
}
