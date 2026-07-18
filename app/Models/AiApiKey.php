<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiApiKey extends Model
{
    protected $fillable = [
        'name',
        'provider',
        'plan',
        'api_key',
        'default_model',
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
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(AiConversation::class);
    }

    /** @return Collection<int, AiApiKey> */
    public static function getAvailableKeys(?string $provider = null): Collection
    {
        $query = static::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('daily_limit')
                    ->orWhereColumn('current_daily_usage', '<', 'daily_limit');
            })
            ->where(function ($q) {
                $q->whereNull('monthly_limit')
                    ->orWhereColumn('current_monthly_usage', '<', 'monthly_limit');
            })
            ->where(function ($q) {
                $q->where('error_count', '<', 5)
                    ->orWhere('last_error_at', '<', now()->subMinutes(30));
            });

        if ($provider) {
            $query->where('provider', $provider);
        }

        return $query
            ->orderByDesc('priority')
            ->orderBy('current_daily_usage')
            ->get();
    }

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

    public function incrementUsage(int $tokens): void
    {
        $this->resetUsageIfNeeded();
        $this->increment('current_daily_usage', $tokens);
        $this->increment('current_monthly_usage', $tokens);
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

    public function maskedKey(): string
    {
        $key = (string) $this->api_key;
        $len = mb_strlen($key);
        if ($len <= 8) {
            return str_repeat('•', max(4, $len));
        }

        return mb_substr($key, 0, 4).str_repeat('•', min(12, $len - 8)).mb_substr($key, -4);
    }

    public function providerLabel(): string
    {
        return (string) (config('ai_chat.providers.'.$this->provider.'.label') ?? $this->provider);
    }

    public function planLabel(): string
    {
        return (string) (config('ai_chat.plans.'.$this->plan) ?? $this->plan);
    }
}
