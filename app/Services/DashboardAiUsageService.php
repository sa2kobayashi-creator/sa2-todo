<?php

namespace App\Services;

use App\Models\Photo;
use App\Models\TranslationApiKey;
use Illuminate\Support\Facades\Cache;

class DashboardAiUsageService
{
    public function __construct(
        private DeeplUsageService $deepl,
        private StabilityAiService $stability,
        private EnhanceConfigService $enhance,
        private AiLlmConfigService $llm,
    ) {}

    /**
     * ダッシュボード用の AI / AI翻訳 使用サマリー。
     *
     * @return array{
     *   ai: array<string, mixed>,
     *   translation: array<string, mixed>
     * }
     */
    public function summary(int $userId, bool $includeEnhance = false): array
    {
        return [
            'ai' => $includeEnhance ? $this->aiEnhanceSummary($userId) : [],
            'translation' => $this->deeplSummary(),
        ];
    }

    /** @return array<string, mixed> */
    private function aiEnhanceSummary(int $userId): array
    {
        $provider = $this->enhance->activeProvider();
        $label = $this->enhance->providerLabel($provider);
        $ready = $this->enhance->isReady($provider);
        $enhanceCount = (int) Photo::query()
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->where('edit_label', 'AI鮮明化')
                    ->orWhere('edit_label', 'AI enhanced');
            })
            ->count();

        $llmReady = $this->llm->isReady();
        $llmLabel = match ($this->llm->activeProvider()) {
            AiLlmConfigService::PROVIDER_GEMINI => 'Gemini',
            default => 'ChatGPT',
        };

        if ($provider === EnhanceConfigService::PROVIDER_STABILITY) {
            $credits = null;
            if ($ready) {
                $cached = Cache::get('dashboard_stability_credits_v');
                if (is_array($cached) && array_key_exists('credits', $cached)) {
                    $credits = $cached['credits'];
                } else {
                    $credits = $this->stability->creditBalance();
                    Cache::put('dashboard_stability_credits_v', ['credits' => $credits], now()->addMinutes(10));
                }
                if ($credits !== null) {
                    $credits = (float) $credits;
                }
            }

            $creditsLabel = $credits !== null
                ? rtrim(rtrim(number_format((float) $credits, 4, '.', ''), '0'), '.')
                : null;

            return [
                'enabled' => $ready,
                'title' => __('AI鮮明化'),
                'provider_label' => $label,
                'meter' => 'credits',
                'status_label' => $ready
                    ? ($creditsLabel !== null
                        ? __('残高 :credits クレジット', ['credits' => $creditsLabel])
                        : __('残高を取得できませんでした'))
                    : __('未設定'),
                'remaining_label' => $creditsLabel !== null
                    ? __('無料枠というより前払い残高です。あと :credits クレジットまで利用できます。', ['credits' => $creditsLabel])
                    : ($ready
                        ? __('クレジット残高を確認できませんでした。設定の接続テストを試してください。')
                        : __('設定 → 鮮明化設定で Stability AI を有効にしてください。')),
                'usage_label' => __('鮮明化 :count 件', ['count' => $enhanceCount]),
                'cost_label' => __('従量（クレジット消費）'),
                'percent' => null,
                'warn' => $credits !== null && (float) $credits < 1,
                'settings_url' => '/settings?section=enhance',
                'llm_note' => $llmReady
                    ? __('音声入力 LLM（:provider）は設定済み。公式トークンは設定 → 使用量で確認できます。', ['provider' => $llmLabel])
                    : null,
            ];
        }

        // Real-ESRGAN / SwinIR（現状は一時停止の可能性あり）
        return [
            'enabled' => $ready,
            'title' => __('AI鮮明化'),
            'provider_label' => $label,
            'meter' => 'local',
            'status_label' => $ready ? __('自前実行・従量課金なし') : __('未設定または一時停止中'),
            'remaining_label' => $ready
                ? __('API クレジットの無料枠制限はありません（電気代・VPS代のみ）。')
                : __('設定 → 鮮明化設定を確認してください。'),
            'usage_label' => __('鮮明化 :count 件', ['count' => $enhanceCount]),
            'cost_label' => '$0'.__('/月').' + VPS/電気',
            'percent' => null,
            'warn' => false,
            'settings_url' => '/settings?section=enhance',
            'llm_note' => $llmReady
                ? __('音声入力 LLM（:provider）は設定済み。公式トークンは設定 → 使用量で確認できます。', ['provider' => $llmLabel])
                : null,
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
