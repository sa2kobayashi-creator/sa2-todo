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
        return MediaStorageSetting::forUse(MediaStorageSetting::PROVIDER_LLM);
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
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_LLM);
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

        $cleanSettings = array_merge(
            $this->cachedMeta($row->settingsArray()),
            [
                'active_provider' => $active,
                'openai_model' => trim((string) ($settings['openai_model'] ?? 'gpt-4o-mini')) ?: 'gpt-4o-mini',
                'gemini_model' => trim((string) ($settings['gemini_model'] ?? 'gemini-2.0-flash')) ?: 'gemini-2.0-flash',
            ]
        );

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
        $row = MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_LLM);

        return [
            'enabled' => (bool) $row->enabled,
            'settings' => [
                'active_provider' => $this->activeProvider(),
                'openai_model' => $this->openaiModel(),
                'gemini_model' => $this->geminiModel(),
            ],
            'openai_model_options' => $this->modelOptions(self::PROVIDER_OPENAI),
            'gemini_model_options' => $this->modelOptions(self::PROVIDER_GEMINI),
            'openai_models_fetched_at' => $this->stringSetting('openai_models_fetched_at'),
            'gemini_models_fetched_at' => $this->stringSetting('gemini_models_fetched_at'),
            'has_openai_key' => $row->hasSecret('openai_api_key'),
            'has_gemini_key' => $row->hasSecret('gemini_api_key'),
            'openai_api_key_masked' => $row->maskedSecret('openai_api_key'),
            'gemini_api_key_masked' => $row->maskedSecret('gemini_api_key'),
            'last_tested_at' => $row->last_tested_at?->format('Y-m-d H:i'),
            'last_test_status' => $row->last_test_status,
            'last_test_message' => $row->last_test_message,
        ];
    }

    /**
     * @return list<string>
     */
    public function modelOptions(string $provider): array
    {
        $current = $provider === self::PROVIDER_GEMINI ? $this->geminiModel() : $this->openaiModel();
        $cached = $this->cachedModelIds($provider);
        $ids = $cached !== [] ? $cached : $this->defaultModelIds($provider);

        return $this->uniqueModels($ids, $current);
    }

    /**
     * @return array{ok: bool, message: string, models: list<string>, fetched_at: string|null}
     */
    public function refreshModels(string $provider): array
    {
        $provider = $provider === self::PROVIDER_GEMINI ? self::PROVIDER_GEMINI : self::PROVIDER_OPENAI;
        $apiKey = $this->apiKeyFor($provider);
        if ($apiKey === '') {
            return [
                'ok' => false,
                'message' => __('APIキーが未設定です'),
                'models' => $this->modelOptions($provider),
                'fetched_at' => null,
            ];
        }

        try {
            $ids = $provider === self::PROVIDER_GEMINI
                ? $this->fetchGeminiModelIds($apiKey)
                : $this->fetchOpenAiModelIds($apiKey);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => mb_substr($e->getMessage(), 0, 300),
                'models' => $this->modelOptions($provider),
                'fetched_at' => null,
            ];
        }

        if ($ids === []) {
            return [
                'ok' => false,
                'message' => __('モデル一覧の取得に失敗しました'),
                'models' => $this->modelOptions($provider),
                'fetched_at' => null,
            ];
        }

        $ids = $this->uniqueModels(array_merge($this->defaultModelIds($provider), $ids), $provider === self::PROVIDER_GEMINI ? $this->geminiModel() : $this->openaiModel());
        $fetchedAt = now()->format('Y-m-d H:i');
        $this->patchSettings([
            $provider.'_model_options' => $ids,
            $provider.'_models_fetched_at' => $fetchedAt,
        ]);

        return [
            'ok' => true,
            'message' => __('モデル一覧を更新しました（:count件）', ['count' => count($ids)]),
            'models' => $ids,
            'fetched_at' => $fetchedAt,
        ];
    }

    /**
     * @return array{
     *   ok: bool,
     *   available: bool,
     *   message: string,
     *   fetched_at: string|null,
     *   input_tokens: int|null,
     *   output_tokens: int|null,
     *   total_tokens: int|null,
     *   requests: int|null,
     *   external: string
     * }
     */
    public function fetchOfficialUsage(string $provider): array
    {
        $provider = $provider === self::PROVIDER_GEMINI ? self::PROVIDER_GEMINI : self::PROVIDER_OPENAI;
        $snapshot = $provider === self::PROVIDER_GEMINI
            ? $this->geminiUsageUnavailable()
            : $this->fetchOpenAiOfficialUsage();
        $this->patchSettings([$provider.'_usage' => $snapshot]);

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    public function officialUsageSnapshot(string $provider): array
    {
        $provider = $provider === self::PROVIDER_GEMINI ? self::PROVIDER_GEMINI : self::PROVIDER_OPENAI;
        $stored = $this->row()->setting($provider.'_usage');
        if (is_array($stored) && $stored !== []) {
            return $stored;
        }

        if ($provider === self::PROVIDER_GEMINI) {
            return $this->geminiUsageUnavailable(false);
        }

        return [
            'ok' => false,
            'available' => true,
            'message' => __('未取得です。「公式使用量を更新」で OpenAI に問い合わせます。'),
            'fetched_at' => null,
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
            'requests' => null,
            'external' => 'https://platform.openai.com/usage',
        ];
    }

    public function recordTestResult(bool $ok, string $message): void
    {
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_LLM);
        $row->fill([
            'last_tested_at' => now(),
            'last_test_status' => $ok ? 'ok' : 'fail',
            'last_test_message' => mb_substr($message, 0, 500),
        ]);
        $row->save();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function cachedMeta(array $settings): array
    {
        $keep = [];
        foreach ([
            'openai_model_options',
            'openai_models_fetched_at',
            'gemini_model_options',
            'gemini_models_fetched_at',
            'openai_usage',
            'gemini_usage',
        ] as $key) {
            if (array_key_exists($key, $settings)) {
                $keep[$key] = $settings[$key];
            }
        }

        return $keep;
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function patchSettings(array $patch): void
    {
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_LLM);
        $row->fill(['settings' => array_merge($row->settingsArray(), $patch)]);
        $row->save();
    }

    private function stringSetting(string $key): ?string
    {
        $value = trim((string) $this->row()->setting($key, ''));

        return $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function cachedModelIds(string $provider): array
    {
        $raw = $this->row()->setting($provider.'_model_options', []);
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $id) {
            if (is_string($id) && trim($id) !== '') {
                $ids[] = trim($id);
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function defaultModelIds(string $provider): array
    {
        return $provider === self::PROVIDER_GEMINI
            ? ['gemini-2.0-flash', 'gemini-2.0-flash-lite', 'gemini-2.5-flash', 'gemini-1.5-flash', 'gemini-1.5-pro']
            : ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1', 'o4-mini'];
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function uniqueModels(array $ids, string $current): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = trim($id);
            if ($id !== '') {
                $out[] = $id;
            }
        }
        $out = array_values(array_unique($out));
        sort($out, SORT_STRING);
        if ($current !== '' && ! in_array($current, $out, true)) {
            array_unshift($out, $current);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function fetchOpenAiModelIds(string $apiKey): array
    {
        $res = Http::withToken($apiKey)
            ->timeout(20)
            ->acceptJson()
            ->get('https://api.openai.com/v1/models');
        if (! $res->successful()) {
            throw new \RuntimeException(mb_substr($res->body(), 0, 300));
        }

        $data = $res->json('data');
        if (! is_array($data)) {
            return [];
        }

        $ids = [];
        foreach ($data as $item) {
            $id = is_array($item) ? trim((string) ($item['id'] ?? '')) : '';
            if ($id !== '' && $this->isOpenAiChatModel($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function isOpenAiChatModel(string $id): bool
    {
        $id = strtolower($id);
        if (str_starts_with($id, 'ft:')) {
            return true;
        }
        foreach ([
            'whisper', 'tts-', 'dall-e', 'embedding', 'moderation', 'transcribe',
            'sora-', 'gpt-image', 'omni-moderation', 'babbage', 'davinci',
            'chatgpt-image', 'audio-', 'realtime', 'codex-mini',
        ] as $deny) {
            if (str_contains($id, $deny)) {
                return false;
            }
        }

        return str_starts_with($id, 'gpt-')
            || str_starts_with($id, 'o1')
            || str_starts_with($id, 'o3')
            || str_starts_with($id, 'o4')
            || str_starts_with($id, 'chatgpt-');
    }

    /**
     * @return list<string>
     */
    private function fetchGeminiModelIds(string $apiKey): array
    {
        $ids = [];
        $pageToken = null;
        for ($page = 0; $page < 5; $page++) {
            $query = ['key' => $apiKey, 'pageSize' => 100];
            if (is_string($pageToken) && $pageToken !== '') {
                $query['pageToken'] = $pageToken;
            }
            $res = Http::timeout(20)
                ->acceptJson()
                ->get('https://generativelanguage.googleapis.com/v1beta/models', $query);
            if (! $res->successful()) {
                throw new \RuntimeException(mb_substr($res->body(), 0, 300));
            }

            $models = $res->json('models');
            if (is_array($models)) {
                foreach ($models as $model) {
                    if (! is_array($model)) {
                        continue;
                    }
                    $methods = $model['supportedGenerationMethods'] ?? [];
                    if (! is_array($methods) || ! in_array('generateContent', $methods, true)) {
                        continue;
                    }
                    $name = (string) ($model['name'] ?? '');
                    $name = preg_replace('#^models/#', '', $name) ?? $name;
                    $name = trim($name);
                    if ($name === '' || ! str_contains(strtolower($name), 'gemini')) {
                        continue;
                    }
                    $ids[] = $name;
                }
            }

            $pageToken = $res->json('nextPageToken');
            if (! is_string($pageToken) || $pageToken === '') {
                break;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOpenAiOfficialUsage(): array
    {
        $apiKey = $this->apiKeyFor(self::PROVIDER_OPENAI);
        $external = 'https://platform.openai.com/usage';
        if ($apiKey === '') {
            return [
                'ok' => false,
                'available' => true,
                'message' => __('APIキーが未設定です'),
                'fetched_at' => now()->format('Y-m-d H:i'),
                'input_tokens' => null,
                'output_tokens' => null,
                'total_tokens' => null,
                'requests' => null,
                'external' => $external,
            ];
        }

        $start = now()->startOfMonth()->utc()->timestamp;
        $input = 0;
        $output = 0;
        $requests = 0;
        $nextPage = null;
        try {
            for ($page = 0; $page < 4; $page++) {
                $query = [
                    'start_time' => $start,
                    'bucket_width' => '1d',
                    'limit' => 31,
                ];
                if (is_string($nextPage) && $nextPage !== '') {
                    $query['next_page'] = $nextPage;
                }
                $res = Http::withToken($apiKey)
                    ->timeout(20)
                    ->acceptJson()
                    ->get('https://api.openai.com/v1/organization/usage/completions', $query);
                if ($res->status() === 401 || $res->status() === 403) {
                    return [
                        'ok' => false,
                        'available' => false,
                        'message' => __('この API キーでは公式の使用量を読めません。OpenAI の管理キー（Usage 読み取り）が必要です。請求は公式コンソールで確認してください。'),
                        'fetched_at' => now()->format('Y-m-d H:i'),
                        'input_tokens' => null,
                        'output_tokens' => null,
                        'total_tokens' => null,
                        'requests' => null,
                        'external' => $external,
                    ];
                }
                if (! $res->successful()) {
                    return [
                        'ok' => false,
                        'available' => true,
                        'message' => mb_substr((string) ($res->json('error.message') ?? $res->body()), 0, 300),
                        'fetched_at' => now()->format('Y-m-d H:i'),
                        'input_tokens' => null,
                        'output_tokens' => null,
                        'total_tokens' => null,
                        'requests' => null,
                        'external' => $external,
                    ];
                }

                $buckets = $res->json('data');
                if (is_array($buckets)) {
                    foreach ($buckets as $bucket) {
                        $results = is_array($bucket) ? ($bucket['results'] ?? []) : [];
                        if (! is_array($results)) {
                            continue;
                        }
                        foreach ($results as $row) {
                            if (! is_array($row)) {
                                continue;
                            }
                            $input += (int) ($row['input_tokens'] ?? 0);
                            $output += (int) ($row['output_tokens'] ?? 0);
                            $requests += (int) ($row['num_model_requests'] ?? 0);
                        }
                    }
                }

                if (! $res->json('has_more')) {
                    break;
                }
                $nextPage = $res->json('next_page');
                if (! is_string($nextPage) || $nextPage === '') {
                    break;
                }
            }
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'available' => true,
                'message' => mb_substr($e->getMessage(), 0, 300),
                'fetched_at' => now()->format('Y-m-d H:i'),
                'input_tokens' => null,
                'output_tokens' => null,
                'total_tokens' => null,
                'requests' => null,
                'external' => $external,
            ];
        }

        return [
            'ok' => true,
            'available' => true,
            'message' => __('OpenAI の今月の Completions 使用量を取得しました'),
            'fetched_at' => now()->format('Y-m-d H:i'),
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $input + $output,
            'requests' => $requests,
            'external' => $external,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function geminiUsageUnavailable(bool $stamp = true): array
    {
        return [
            'ok' => false,
            'available' => false,
            'message' => __('Gemini の API キーでは使用量を取得できません。Google AI Studio の Usage を参照してください。'),
            'fetched_at' => $stamp ? now()->format('Y-m-d H:i') : null,
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
            'requests' => null,
            'external' => 'https://aistudio.google.com/usage',
        ];
    }
}
