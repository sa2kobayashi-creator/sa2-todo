<?php

namespace App\Services;

use App\Models\TranslationApiKey;

class AiProviderUsageService
{
    public function __construct(
        private AiLlmConfigService $llm,
        private CloudflareWorkersAiConfigService $workersAi,
        private DeeplUsageService $deepl,
        private IntegrationUsageService $integrationUsage,
    ) {}

    /**
     * @return array{
     *   deepl: array<string, mixed>,
     *   openai: array<string, mixed>,
     *   gemini: array<string, mixed>,
     *   workers_ai: array<string, mixed>
     * }
     */
    public function summaries(): array
    {
        return [
            'deepl' => $this->deeplSummary(),
            'openai' => $this->llmCard(
                'ChatGPT (OpenAI)',
                AiLlmConfigService::PROVIDER_OPENAI,
                'openai',
            ),
            'gemini' => $this->llmCard(
                'Gemini (Google)',
                AiLlmConfigService::PROVIDER_GEMINI,
                'gemini',
            ),
            'workers_ai' => $this->workersCard(),
        ];
    }

    /**
     * @return array{ok: int, failed: int, skipped: int, message: string}
     */
    public function refreshAll(): array
    {
        $ok = 0;
        $failed = 0;
        $skipped = 0;

        $openai = $this->llm->fetchOfficialUsage(AiLlmConfigService::PROVIDER_OPENAI);
        if (! empty($openai['ok'])) {
            $ok++;
        } elseif (($openai['available'] ?? true) === false) {
            $skipped++;
        } else {
            $failed++;
        }

        $this->llm->fetchOfficialUsage(AiLlmConfigService::PROVIDER_GEMINI);
        $skipped++;

        $workers = $this->workersAi->fetchOfficialUsage();
        if (! empty($workers['ok'])) {
            $ok++;
        } elseif (($workers['available'] ?? true) === false) {
            $skipped++;
        } else {
            $failed++;
        }

        $keys = TranslationApiKey::queryForCurrentTenant(true)
            ->where('provider', 'deepl')
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();
        if ($keys->isEmpty()) {
            $skipped++;
        } else {
            $deeplOk = 0;
            foreach ($keys as $key) {
                $result = $this->deepl->fetchAndStore($key);
                if (! empty($result['ok'])) {
                    $deeplOk++;
                }
            }
            if ($deeplOk > 0) {
                $ok++;
            } else {
                $failed++;
            }
        }

        if ($ok === 0 && $failed > 0) {
            $message = __('公式使用量の更新に失敗しました。各カードの説明を確認してください。');
        } elseif ($failed > 0) {
            $message = __('公式使用量を一部更新しました（取得できないサービスはカードに理由を表示しています）。');
        } else {
            $message = __('公式使用量を更新しました。');
        }

        return ['ok' => $ok, 'failed' => $failed, 'skipped' => $skipped, 'message' => $message];
    }

    /** @return array<string, mixed> */
    private function deeplSummary(): array
    {
        $keys = TranslationApiKey::queryForCurrentTenant(true)
            ->where('provider', 'deepl')
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        $cards = [];
        foreach ($keys as $key) {
            $summary = $this->deepl->usageSummary($key);
            $cards[] = [
                'name' => (string) ($key->name ?: __('キー #:id', ['id' => $key->id])),
                'is_paid' => (bool) $summary['is_paid_plan'],
                'character_count' => (int) $summary['character_count'],
                'character_limit' => $summary['character_limit'],
                'usage_rate' => $summary['usage_rate'],
                'estimated_cost' => $summary['estimated_cost'],
                'fetched_at' => $summary['fetched_at'],
            ];
        }

        return [
            'id' => 'deepl',
            'label' => 'DeepL',
            'available' => true,
            'ok' => $cards !== [],
            'message' => $cards === []
                ? __('APIキー登録後に使用量を取得できます。')
                : __('DeepL /v2/usage から取得した文字数です。'),
            'external' => 'https://www.deepl.com/pro-account/usage',
            'keys' => $cards,
            'observed' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function llmCard(string $label, string $provider, string $tokenProvider): array
    {
        $snap = $this->llm->officialUsageSnapshot($provider);
        $observed = $this->integrationUsage->totals($tokenProvider, 'tokens');

        return [
            'id' => $provider,
            'label' => $label,
            'available' => (bool) ($snap['available'] ?? true),
            'ok' => (bool) ($snap['ok'] ?? false),
            'message' => (string) ($snap['message'] ?? ''),
            'fetched_at' => $snap['fetched_at'] ?? null,
            'external' => (string) ($snap['external'] ?? ''),
            'input_tokens' => $snap['input_tokens'] ?? null,
            'output_tokens' => $snap['output_tokens'] ?? null,
            'total_tokens' => $snap['total_tokens'] ?? null,
            'requests' => $snap['requests'] ?? null,
            'neurons' => null,
            'observed' => $observed,
            'keys' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function workersCard(): array
    {
        $snap = $this->workersAi->officialUsageSnapshot();
        $observed = $this->integrationUsage->totals('workers_ai', 'tokens');

        return [
            'id' => 'workers_ai',
            'label' => 'Cloudflare Workers AI',
            'available' => (bool) ($snap['available'] ?? true),
            'ok' => (bool) ($snap['ok'] ?? false),
            'message' => (string) ($snap['message'] ?? ''),
            'fetched_at' => $snap['fetched_at'] ?? null,
            'external' => (string) ($snap['external'] ?? ''),
            'input_tokens' => $snap['input_tokens'] ?? null,
            'output_tokens' => $snap['output_tokens'] ?? null,
            'total_tokens' => $snap['total_tokens'] ?? null,
            'requests' => $snap['requests'] ?? null,
            'neurons' => $snap['neurons'] ?? null,
            'observed' => $observed,
            'keys' => [],
        ];
    }
}
