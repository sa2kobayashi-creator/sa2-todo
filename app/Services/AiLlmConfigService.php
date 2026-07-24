<?php

namespace App\Services;

use App\Models\MediaStorageSetting;
use Illuminate\Support\Facades\Http;

class AiLlmConfigService
{
    public const PROVIDER_OPENAI = 'openai';

    public const PROVIDER_GEMINI = 'gemini';

    public function row(): MediaStorageSetting
    {
        return MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_LLM);
    }

    public function isReady(): bool
    {
        $row = $this->row();
        if (! $row->enabled) {
            return false;
        }

        return $this->apiKeyFor($this->activeProvider()) !== '';
    }

    public function activeProvider(): string
    {
        $provider = (string) $this->row()->setting('active_provider', self::PROVIDER_OPENAI);

        return in_array($provider, [self::PROVIDER_OPENAI, self::PROVIDER_GEMINI], true)
            ? $provider
            : self::PROVIDER_OPENAI;
    }

    public function openaiModel(): string
    {
        $model = trim((string) $this->row()->setting('openai_model', 'gpt-4o-mini'));

        return $model !== '' ? $model : 'gpt-4o-mini';
    }

    public function geminiModel(): string
    {
        $model = trim((string) $this->row()->setting('gemini_model', 'gemini-2.0-flash'));

        return $model !== '' ? $model : 'gemini-2.0-flash';
    }

    public function apiKeyFor(string $provider): string
    {
        $row = $this->row();
        $key = match ($provider) {
            self::PROVIDER_OPENAI => (string) $row->secret('openai_api_key', ''),
            self::PROVIDER_GEMINI => (string) $row->secret('gemini_api_key', ''),
            default => '',
        };

        return trim($key);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $secrets
     */
    public function save(bool $enabled, array $settings, array $secrets): MediaStorageSetting
    {
        $row = $this->row();
        $mergedSecrets = $row->secretsArray();
        foreach ($secrets as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            $trimmed = is_string($value) ? trim($value) : '';
            if ($trimmed === '' || $trimmed === '••••••••' || str_starts_with($trimmed, '••••')) {
                continue;
            }
            $mergedSecrets[$key] = $trimmed;
        }

        $active = (string) ($settings['active_provider'] ?? self::PROVIDER_OPENAI);
        if (! in_array($active, [self::PROVIDER_OPENAI, self::PROVIDER_GEMINI], true)) {
            $active = self::PROVIDER_OPENAI;
        }

        $cleanSettings = [
            'active_provider' => $active,
            'openai_model' => trim((string) ($settings['openai_model'] ?? 'gpt-4o-mini')) ?: 'gpt-4o-mini',
            'gemini_model' => trim((string) ($settings['gemini_model'] ?? 'gemini-2.0-flash')) ?: 'gemini-2.0-flash',
        ];

        $row->fill([
            'enabled' => $enabled,
            'settings' => $cleanSettings,
            'secrets' => $mergedSecrets,
        ]);
        $row->save();

        return $row->fresh() ?? $row;
    }

    /** @return array{ok: bool, message: string} */
    public function testConnection(?string $provider = null): array
    {
        $provider = $provider ?: $this->activeProvider();
        $apiKey = $this->apiKeyFor($provider);
        if ($apiKey === '') {
            return ['ok' => false, 'message' => __('APIキーが未設定です')];
        }

        try {
            if ($provider === self::PROVIDER_OPENAI) {
                $res = Http::withToken($apiKey)
                    ->timeout(20)
                    ->acceptJson()
                    ->get('https://api.openai.com/v1/models');
                if (! $res->successful()) {
                    return ['ok' => false, 'message' => mb_substr($res->body(), 0, 300)];
                }

                return ['ok' => true, 'message' => __('OpenAI に接続できました')];
            }

            $model = rawurlencode($this->geminiModel());
            $res = Http::timeout(20)
                ->acceptJson()
                ->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=".urlencode($apiKey),
                    [
                        'contents' => [
                            ['parts' => [['text' => 'Reply with OK only.']]],
                        ],
                        'generationConfig' => [
                            'temperature' => 0,
                            'maxOutputTokens' => 8,
                        ],
                    ]
                );
            if (! $res->successful()) {
                return ['ok' => false, 'message' => mb_substr($res->body(), 0, 300)];
            }

            return ['ok' => true, 'message' => __('Gemini に接続できました')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => mb_substr($e->getMessage(), 0, 300)];
        }
    }

    /** @return array<string, mixed> */
    public function formState(): array
    {
        $row = $this->row();

        return [
            'enabled' => (bool) $row->enabled,
            'settings' => [
                'active_provider' => $this->activeProvider(),
                'openai_model' => $this->openaiModel(),
                'gemini_model' => $this->geminiModel(),
            ],
            'has_openai_key' => $row->hasSecret('openai_api_key'),
            'has_gemini_key' => $row->hasSecret('gemini_api_key'),
            'openai_api_key_masked' => $row->maskedSecret('openai_api_key'),
            'gemini_api_key_masked' => $row->maskedSecret('gemini_api_key'),
            'last_tested_at' => $row->last_tested_at?->format('Y-m-d H:i'),
            'last_test_status' => $row->last_test_status,
            'last_test_message' => $row->last_test_message,
        ];
    }

    public function recordTestResult(bool $ok, string $message): void
    {
        $row = $this->row();
        $row->fill([
            'last_tested_at' => now(),
            'last_test_status' => $ok ? 'ok' : 'fail',
            'last_test_message' => mb_substr($message, 0, 500),
        ]);
        $row->save();
    }
}
