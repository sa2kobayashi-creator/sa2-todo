<?php

namespace App\Services;

use App\Models\TranslationApiKey;

class DashboardAiUsageService
{
    public function __construct(
        private DeeplUsageService $deepl,
    ) {}

    /**
     * ダッシュボード用の AI翻訳 使用サマリー。
     *
     * @return array{
     *   ai: array<string, mixed>,
     *   translation: array<string, mixed>
     * }
     */
    public function summary(int $userId, bool $includeEnhance = false): array
    {
        return [
            'ai' => [],
            'translation' => $this->deeplSummary(),
        ];
    }

    /** @return array<string, mixed> */
    private function deeplSummary(): array
    {
        $keys = TranslationApiKey::queryForCurrentTenant(true)
            ->where('provider', 'deepl')
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->get();

        if ($keys->isEmpty()) {
            return [
                'enabled' => false,
                'title' => __('AI翻訳（DeepL）'),
                'provider_label' => 'DeepL',
                'status_label' => __('未設定'),
                'remaining_label' => __('設定 → AI で DeepL API キーを登録してください。'),
                'usage_label' => '—',
                'cost_label' => '—',
                'percent' => null,
                'warn' => false,
                'keys' => [],
                'settings_url' => '/settings?section=ai#deepl-usage-overview',
                'fetched_at' => null,
            ];
        }

        $freeRemaining = 0;
        $freeLimit = 0;
        $freeUsed = 0;
        $paidEstimated = 0.0;
        $hasPaid = false;
        $hasFree = false;
        $fetchedAt = null;
        $keyCards = [];

        foreach ($keys as $key) {
            $summary = $this->deepl->usageSummary($key);
            $count = (int) $summary['character_count'];
            $limit = $summary['character_limit'] !== null ? (int) $summary['character_limit'] : null;
            $rate = $summary['usage_rate'];
            $isPaid = (bool) $summary['is_paid_plan'];

            if ($summary['fetched_at'] && ($fetchedAt === null || $summary['fetched_at'] > $fetchedAt)) {
                $fetchedAt = $summary['fetched_at'];
            }

            if ($isPaid) {
                $hasPaid = true;
                $paidEstimated += (float) ($summary['estimated_cost'] ?? 0);
            } else {
                $hasFree = true;
                if ($limit !== null && $limit > 0) {
                    $freeLimit += $limit;
                    $freeUsed += min($count, $limit);
                    $freeRemaining += max(0, $limit - $count);
                }
            }

            $keyCards[] = [
                'name' => (string) ($key->name ?: __('キー #:id', ['id' => $key->id])),
                'is_paid' => $isPaid,
                'character_count' => $count,
                'character_limit' => $limit,
                'usage_rate' => $rate,
                'remaining' => ($limit !== null) ? max(0, $limit - $count) : null,
                'estimated_cost' => $summary['estimated_cost'],
            ];
        }

        $percent = ($freeLimit > 0)
            ? round(($freeUsed / $freeLimit) * 100, 1)
            : null;

        if ($hasFree && $freeLimit > 0) {
            $remainingLabel = __('無料枠はあと :remaining 文字まで（上限 :limit 文字・使用率 :rate%）。', [
                'remaining' => number_format($freeRemaining),
                'limit' => number_format($freeLimit),
                'rate' => number_format((float) $percent, 1),
            ]);
        } elseif ($hasPaid && ! $hasFree) {
            $remainingLabel = __('有料プランです。月額基本 + 従量の推定合計は約 €:cost です。', [
                'cost' => rtrim(rtrim(number_format($paidEstimated, 4, '.', ''), '0'), '.') ?: '0',
            ]);
        } else {
            $remainingLabel = __('使用量データがまだありません。設定画面で「DeepL使用量を更新」を実行してください。');
        }

        $usageParts = [];
        if ($hasFree && $freeLimit > 0) {
            $usageParts[] = number_format($freeUsed).' / '.number_format($freeLimit).__('文字');
        }
        if ($hasPaid) {
            $usageParts[] = __('有料キー推定 €:cost', [
                'cost' => rtrim(rtrim(number_format($paidEstimated, 4, '.', ''), '0'), '.') ?: '0',
            ]);
        }

        return [
            'enabled' => true,
            'title' => __('AI翻訳（DeepL）'),
            'provider_label' => 'DeepL',
            'status_label' => $hasFree && $hasPaid
                ? __('無料 + 有料キー')
                : ($hasPaid ? __('有料プラン') : __('無料プラン')),
            'remaining_label' => $remainingLabel,
            'usage_label' => $usageParts !== [] ? implode(' · ', $usageParts) : '—',
            'cost_label' => $hasPaid
                ? __('推定 €:cost/月', [
                    'cost' => rtrim(rtrim(number_format($paidEstimated, 4, '.', ''), '0'), '.') ?: '0',
                ])
                : __('従量料金なし（無料枠内）'),
            'percent' => $percent,
            'warn' => ($percent !== null && $percent >= 80) || ($hasFree && $freeRemaining <= 0 && $freeLimit > 0),
            'keys' => $keyCards,
            'settings_url' => '/settings?section=ai#deepl-usage-overview',
            'fetched_at' => $fetchedAt,
        ];
    }
}
