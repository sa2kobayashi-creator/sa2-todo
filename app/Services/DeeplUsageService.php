<?php

namespace App\Services;

use App\Models\MediaStorageSetting;
use App\Models\TranslationApiKey;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeeplUsageService
{
    public function pricingRow(): MediaStorageSetting
    {
        return MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_DEEPL);
    }

    public function monthlyBaseEur(): float
    {
        $value = $this->pricingRow()->setting(
            'paid_monthly_base_eur',
            config('deepl.paid_monthly_base_eur', 5.49)
        );

        return max(0, (float) $value);
    }

    public function perMillionCharsEur(): float
    {
        $value = $this->pricingRow()->setting(
            'paid_per_million_chars_eur',
            config('deepl.paid_per_million_chars_eur', 20.0)
        );

        return max(0, (float) $value);
    }

    /**
     * @param  array{paid_monthly_base_eur?: mixed, paid_per_million_chars_eur?: mixed}  $settings
     */
    public function savePricing(array $settings): MediaStorageSetting
    {
        $row = $this->pricingRow();
        $row->fill([
            'enabled' => true,
            'settings' => [
                'paid_monthly_base_eur' => max(0, (float) ($settings['paid_monthly_base_eur'] ?? $this->monthlyBaseEur())),
                'paid_per_million_chars_eur' => max(0, (float) ($settings['paid_per_million_chars_eur'] ?? $this->perMillionCharsEur())),
            ],
            'secrets' => $row->secretsArray(),
        ]);
        $row->save();

        return $row->fresh() ?? $row;
    }

    /** @return array{paid_monthly_base_eur: float, paid_per_million_chars_eur: float} */
    public function pricingFormState(): array
    {
        return [
            'paid_monthly_base_eur' => $this->monthlyBaseEur(),
            'paid_per_million_chars_eur' => $this->perMillionCharsEur(),
        ];
    }

    public function isPaidKey(TranslationApiKey $key): bool
    {
        return ! str_contains((string) $key->api_key, ':fx');
    }

    public function usageEndpoint(TranslationApiKey $key): string
    {
        if ($key->api_url) {
            $base = preg_replace('#/v2/translate/?$#', '', rtrim((string) $key->api_url, '/'));
            if (is_string($base) && $base !== '') {
                return $base.'/v2/usage';
            }
        }

        return $this->isPaidKey($key)
            ? 'https://api.deepl.com/v2/usage'
            : 'https://api-free.deepl.com/v2/usage';
    }

    /**
     * @return array{
     *   ok: bool,
     *   message?: string,
     *   character_count?: int,
     *   character_limit?: int|null,
     *   is_paid_plan?: bool,
     *   estimated_cost?: float|null,
     *   monthly_base_fee?: float|null,
     *   usage_cost?: float|null,
     *   usage_rate?: float|null,
     *   fetched_at?: string|null
     * }
     */
    public function fetchAndStore(TranslationApiKey $key): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'DeepL-Auth-Key '.$key->api_key,
            ])->timeout(15)->get($this->usageEndpoint($key));

            if (! $response->successful()) {
                $message = $response->json('message') ?? $response->body();

                return ['ok' => false, 'message' => __('使用量の取得に失敗しました: :msg', ['msg' => mb_substr((string) $message, 0, 200)])];
            }

            $data = $response->json();
            if (! is_array($data)) {
                return ['ok' => false, 'message' => __('使用量の取得に失敗しました。')];
            }

            $characterCount = (int) ($data['character_count'] ?? 0);
            $characterLimit = array_key_exists('character_limit', $data)
                ? (int) $data['character_limit']
                : null;

            // DeepL は制限未設定時に巨大な数を返すことがある
            if ($characterLimit !== null && $characterLimit >= 1_000_000_000_000) {
                $characterLimit = $this->isPaidKey($key)
                    ? null
                    : (int) config('deepl.free_monthly_character_limit', 500_000);
            }

            if ($characterLimit === null && ! $this->isPaidKey($key)) {
                $characterLimit = (int) config('deepl.free_monthly_character_limit', 500_000);
            }

            $key->forceFill([
                'deepl_character_count' => $characterCount,
                'deepl_character_limit' => $characterLimit,
                'deepl_usage_fetched_at' => now(),
                'current_monthly_usage' => $characterCount,
                'monthly_limit' => $characterLimit,
                'last_monthly_reset_date' => now()->format('Y-m-01'),
            ])->save();

            return array_merge(
                ['ok' => true, 'message' => __('DeepL使用量を更新しました。')],
                $this->usageSummary($key->fresh() ?? $key)
            );
        } catch (\Throwable $e) {
            Log::error('DeepL usage fetch failed', ['id' => $key->id, 'error' => $e->getMessage()]);

            return ['ok' => false, 'message' => __('使用量の取得に失敗しました: :msg', ['msg' => mb_substr($e->getMessage(), 0, 200)])];
        }
    }

    /**
     * @return array{
     *   character_count: int,
     *   character_limit: int|null,
     *   is_paid_plan: bool,
     *   estimated_cost: float|null,
     *   monthly_base_fee: float|null,
     *   usage_cost: float|null,
     *   usage_rate: float|null,
     *   fetched_at: string|null
     * }
     */
    public function usageSummary(TranslationApiKey $key): array
    {
        $count = (int) ($key->deepl_character_count ?? $key->current_monthly_usage ?? 0);
        $limit = $key->deepl_character_limit !== null
            ? (int) $key->deepl_character_limit
            : ($key->monthly_limit !== null ? (int) $key->monthly_limit : null);
        $isPaid = $this->isPaidKey($key);

        $monthlyBase = null;
        $usageCost = null;
        $estimated = null;
        if ($isPaid) {
            $monthlyBase = $this->monthlyBaseEur();
            $perChar = $this->perMillionCharsEur() / 1_000_000;
            $usageCost = round($count * $perChar, 4);
            $estimated = round($monthlyBase + $usageCost, 4);
        }

        $rate = null;
        if ($limit !== null && $limit > 0) {
            $rate = round(($count / $limit) * 100, 1);
        }

        return [
            'character_count' => $count,
            'character_limit' => $limit,
            'is_paid_plan' => $isPaid,
            'estimated_cost' => $estimated,
            'monthly_base_fee' => $monthlyBase,
            'usage_cost' => $usageCost,
            'usage_rate' => $rate,
            'fetched_at' => $key->deepl_usage_fetched_at?->format('Y-m-d H:i'),
        ];
    }
}
