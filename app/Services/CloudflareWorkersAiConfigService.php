<?php

namespace App\Services;

use App\Models\MediaStorageSetting;
use Illuminate\Support\Facades\Http;

class CloudflareWorkersAiConfigService
{
    public const DEFAULT_MODEL = '@cf/meta/llama-3.1-8b-instruct-fp8';

    public function row(): MediaStorageSetting
    {
        return MediaStorageSetting::forUse(MediaStorageSetting::PROVIDER_WORKERS_AI);
    }

    public function isReady(): bool
    {
        return $this->apiToken() !== '' && $this->accountId() !== '';
    }

    public function accountId(): string
    {
        $row = $this->row();
        $fromDb = self::normalizeAccountId((string) $row->setting('account_id', ''));
        if ($fromDb !== '') {
            return $row->enabled ? $fromDb : '';
        }

        return $this->accountIdFromEnv();
    }

    public function apiToken(): string
    {
        $row = $this->row();
        $fromDb = self::normalizeApiToken((string) $row->secret('api_token', ''));
        if ($fromDb !== '') {
            return $row->enabled ? $fromDb : '';
        }

        return self::normalizeApiToken((string) config('services.cloudflare_workers_ai.api_token', ''));
    }

    public function model(): string
    {
        $row = $this->row();
        $fromDb = trim((string) $row->setting('model', ''));
        if ($fromDb !== '') {
            return $fromDb;
        }
        $fromEnv = trim((string) config('services.cloudflare_workers_ai.model', ''));

        return $fromEnv !== '' ? $fromEnv : self::DEFAULT_MODEL;
    }

    /**
     * ダッシュボードの URL や curl 例を丸ごと貼られても ID だけ拾う。拾えなければ空。
     */
    public static function normalizeAccountId(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('#accounts/([0-9a-f]{32})#i', $raw, $m)) {
            return strtolower($m[1]);
        }
        if (preg_match('/(?<![0-9a-z])[0-9a-f]{32}(?![0-9a-z])/i', $raw, $m)) {
            return strtolower($m[0]);
        }

        return '';
    }

    public static function normalizeApiToken(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/Bearer\s+([A-Za-z0-9._-]+)/i', $raw, $m)) {
            return $m[1];
        }

        return trim($raw, " \t\n\r\"'");
    }

    public static function normalizeModel(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('#@[a-z0-9-]+/[A-Za-z0-9._/-]+#', $raw, $m)) {
            return rtrim($m[0], '/');
        }

        return self::DEFAULT_MODEL;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $secrets
     */
    public function save(bool $enabled, array $settings, array $secrets): MediaStorageSetting
    {
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_WORKERS_AI);
        $mergedSecrets = $row->secretsArray();
        $token = self::normalizeApiToken((string) ($secrets['api_token'] ?? ''));
        if ($token !== '' && ! str_starts_with($token, '••••')) {
            $mergedSecrets['api_token'] = $token;
        }

        $row->fill([
            'enabled' => $enabled,
            'settings' => array_merge(
                $this->cachedMeta($row->settingsArray()),
                [
                    'account_id' => self::normalizeAccountId((string) ($settings['account_id'] ?? '')),
                    'model' => self::normalizeModel((string) ($settings['model'] ?? '')),
                ]
            ),
            'secrets' => $mergedSecrets,
        ]);
        $row->save();

        return $row->fresh() ?? $row;
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{ok: bool, text: string, message: string}
     */
    public function complete(array $messages, int $maxTokens = 512, bool $requireText = true): array
    {
        $accountId = $this->accountId();
        $token = $this->apiToken();
        $model = $this->model();
        if ($accountId === '' || $token === '') {
            return [
                'ok' => false,
                'text' => '',
                'message' => __('Cloudflare Workers AI が未設定です。設定 → AI にアカウント ID と API トークンを入れてください。'),
            ];
        }

        $url = 'https://api.cloudflare.com/client/v4/accounts/'
            .rawurlencode($accountId)
            .'/ai/run/'
            .$this->encodeModelPath($model);

        try {
            $res = Http::withToken($token)
                ->timeout(max(10, (int) config('services.cloudflare_workers_ai.timeout', 45)))
                ->acceptJson()
                ->post($url, [
                    'messages' => $messages,
                    'max_tokens' => $this->maxTokensForRequest($maxTokens),
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'text' => '', 'message' => mb_substr($e->getMessage(), 0, 300)];
        }

        $payload = $res->json();

        if (! $res->successful() || (is_array($payload) && ($payload['success'] ?? true) === false)) {
            return [
                'ok' => false,
                'text' => '',
                'message' => $this->errorMessage(is_array($payload) ? $payload : [], $res->body()),
            ];
        }

        if (! is_array($payload)) {
            return ['ok' => false, 'text' => '', 'message' => __('応答を解析できませんでした。')];
        }

        $text = $this->extractText($payload);
        if ($text === '' && $requireText) {
            return ['ok' => false, 'text' => '', 'message' => __('空の応答でした。モデル名を確認してください。')];
        }

        if ($text !== '') {
            $this->recordObservedTokens($payload);
        }

        return ['ok' => true, 'text' => $text, 'message' => __('応答を取得しました')];
    }

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array
    {
        // 接続テストは API が通れば成功。Qwen3 などは思考で本文が空になることがある。
        $result = $this->complete([
            ['role' => 'user', 'content' => 'Reply with the word OK only.'],
        ], 768, false);

        if (! $result['ok']) {
            return ['ok' => false, 'message' => $result['message']];
        }

        return ['ok' => true, 'message' => __('Workers AI に接続できました')];
    }

    /** @return array<string, mixed> */
    public function formState(): array
    {
        $row = MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_WORKERS_AI);

        return [
            'enabled' => (bool) $row->enabled,
            'settings' => [
                'account_id' => (string) $row->setting('account_id', ''),
                'model' => $this->model(),
            ],
            'model_options' => $this->modelOptions(),
            'models_fetched_at' => $this->stringSetting('models_fetched_at'),
            'has_api_token' => $row->hasSecret('api_token'),
            'api_token_masked' => $row->maskedSecret('api_token'),
            'last_tested_at' => $row->last_tested_at?->format('Y-m-d H:i'),
            'last_test_status' => $row->last_test_status,
            'last_test_message' => $row->last_test_message,
        ];
    }

    /**
     * @return list<string>
     */
    public function modelOptions(): array
    {
        $cached = $this->cachedModelIds();
        $ids = $cached !== [] ? $cached : $this->defaultModelIds();

        return $this->uniqueModels($ids, $this->model());
    }

    /**
     * @return array{ok: bool, message: string, models: list<string>, fetched_at: string|null}
     */
    public function refreshModels(): array
    {
        $accountId = $this->accountId();
        $token = $this->apiToken();
        if ($accountId === '' || $token === '') {
            return [
                'ok' => false,
                'message' => __('Cloudflare Workers AI が未設定です。設定 → AI にアカウント ID と API トークンを入れてください。'),
                'models' => $this->modelOptions(),
                'fetched_at' => null,
            ];
        }

        try {
            $ids = $this->fetchModelIds($accountId, $token);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => mb_substr($e->getMessage(), 0, 300),
                'models' => $this->modelOptions(),
                'fetched_at' => null,
            ];
        }

        if ($ids === []) {
            return [
                'ok' => false,
                'message' => __('モデル一覧の取得に失敗しました'),
                'models' => $this->modelOptions(),
                'fetched_at' => null,
            ];
        }

        $ids = $this->uniqueModels(array_merge($this->defaultModelIds(), $ids), $this->model());
        $fetchedAt = now()->format('Y-m-d H:i');
        $this->patchSettings([
            'model_options' => $ids,
            'models_fetched_at' => $fetchedAt,
        ]);

        return [
            'ok' => true,
            'message' => __('モデル一覧を更新しました（:count件）', ['count' => count($ids)]),
            'models' => $ids,
            'fetched_at' => $fetchedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchOfficialUsage(): array
    {
        $snapshot = $this->fetchWorkersOfficialUsage();
        $this->patchSettings(['official_usage' => $snapshot]);

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    public function officialUsageSnapshot(): array
    {
        $stored = $this->row()->setting('official_usage');
        if (is_array($stored) && $stored !== []) {
            return $stored;
        }

        return [
            'ok' => false,
            'available' => true,
            'message' => __('未取得です。「公式使用量を更新」で Cloudflare Analytics に問い合わせます。'),
            'fetched_at' => null,
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
            'requests' => null,
            'neurons' => null,
            'external' => 'https://dash.cloudflare.com/?to=/:account/workers/ai',
        ];
    }

    public function recordTestResult(bool $ok, string $message): void
    {
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_WORKERS_AI);
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
        foreach (['model_options', 'models_fetched_at', 'official_usage'] as $key) {
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
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_WORKERS_AI);
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
    private function cachedModelIds(): array
    {
        $raw = $this->row()->setting('model_options', []);
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
    private function defaultModelIds(): array
    {
        return [
            self::DEFAULT_MODEL,
            '@cf/meta/llama-3.1-8b-instruct',
            '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            '@cf/meta/llama-4-scout-17b-16e-instruct',
        ];
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
    private function fetchModelIds(string $accountId, string $token): array
    {
        $ids = $this->searchModels($accountId, $token, 'Text Generation');
        if ($ids === []) {
            $ids = $this->searchModels($accountId, $token, null);
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function searchModels(string $accountId, string $token, ?string $task): array
    {
        $ids = [];
        $url = 'https://api.cloudflare.com/client/v4/accounts/'.rawurlencode($accountId).'/ai/models/search';
        for ($page = 1; $page <= 5; $page++) {
            $query = [
                'page' => $page,
                'per_page' => 50,
            ];
            if ($task !== null) {
                $query['task'] = $task;
            }
            $res = Http::withToken($token)
                ->timeout(20)
                ->acceptJson()
                ->get($url, $query);
            $payload = $res->json();
            if (! $res->successful() || (is_array($payload) && ($payload['success'] ?? true) === false)) {
                throw new \RuntimeException($this->errorMessage(is_array($payload) ? $payload : [], $res->body()));
            }

            $items = is_array($payload) ? ($payload['result'] ?? []) : [];
            if (is_array($items) && isset($items['data']) && is_array($items['data'])) {
                $items = $items['data'];
            }
            if (! is_array($items) || $items === [] || ! array_is_list($items)) {
                break;
            }
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $id = $this->catalogModelName($item);
                if ($id === '') {
                    continue;
                }
                $taskName = '';
                if (is_array($item['task'] ?? null)) {
                    $taskName = (string) ($item['task']['name'] ?? '');
                } elseif (is_string($item['task'] ?? null)) {
                    $taskName = (string) $item['task'];
                }
                $blob = strtolower($id.' '.$taskName);
                if ($task === null && ! str_contains($blob, 'text generation') && ! str_contains($blob, 'instruct')) {
                    continue;
                }
                $ids[] = $id;
            }
            if (count($items) < 50) {
                break;
            }
        }

        return $ids;
    }

    /**
     * Cloudflare の catalog は id が UUID、実行時のモデル名は name（@cf/...）に入る。
     *
     * @param  array<string, mixed>  $item
     */
    private function catalogModelName(array $item): string
    {
        foreach (['name', 'id'] as $key) {
            $value = trim((string) ($item[$key] ?? ''));
            if ($value !== '' && str_starts_with($value, '@')) {
                return $value;
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchWorkersOfficialUsage(): array
    {
        $accountId = $this->accountId();
        $token = $this->apiToken();
        $external = 'https://dash.cloudflare.com/?to=/:account/workers/ai';
        $base = [
            'ok' => false,
            'available' => true,
            'message' => '',
            'fetched_at' => now()->format('Y-m-d H:i'),
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
            'requests' => null,
            'neurons' => null,
            'external' => $external,
        ];
        if ($accountId === '' || $token === '') {
            $base['message'] = __('Cloudflare Workers AI が未設定です。設定 → AI にアカウント ID と API トークンを入れてください。');

            return $base;
        }

        $start = now()->startOfMonth()->utc()->format('Y-m-d\TH:i:s\Z');
        $end = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $queries = [
            $this->workersAiGraphqlQuery('aiInferenceAdaptiveGroups', true, $accountId, $start, $end),
            $this->workersAiGraphqlQuery('aiInferenceAdaptiveGroups', false, $accountId, $start, $end),
            $this->workersAiGraphqlQuery('workersAiInferenceAdaptiveGroups', false, $accountId, $start, $end),
        ];

        $lastError = '';
        foreach ($queries as $query) {
            try {
                $res = Http::withToken($token)
                    ->timeout(20)
                    ->acceptJson()
                    ->asJson()
                    ->post('https://api.cloudflare.com/client/v4/graphql', ['query' => $query]);
                $payload = $res->json();
                if ($res->status() === 401 || $res->status() === 403) {
                    $base['available'] = false;
                    $base['message'] = __('Cloudflare の Analytics 読み取り権限が必要です。Workers AI 実行用トークンだけでは使用量を読めないことがあります。');

                    return $base;
                }
                $errors = is_array($payload) ? ($payload['errors'] ?? []) : [];
                if (is_array($errors) && $errors !== []) {
                    $lastError = (string) ($errors[0]['message'] ?? json_encode($errors[0]));
                    if (str_contains(strtolower($lastError), 'not authorized') || str_contains(strtolower($lastError), 'permission')) {
                        $base['available'] = false;
                        $base['message'] = __('Cloudflare の Analytics 読み取り権限が必要です。Workers AI 実行用トークンだけでは使用量を読めないことがあります。');

                        return $base;
                    }
                    continue;
                }
                if (! is_array($payload) || ! $res->successful()) {
                    $lastError = mb_substr($res->body(), 0, 300);
                    continue;
                }

                $groups = data_get($payload, 'data.viewer.accounts.0.aiInferenceAdaptiveGroups');
                if (! is_array($groups)) {
                    $groups = data_get($payload, 'data.viewer.accounts.0.workersAiInferenceAdaptiveGroups');
                }
                if (! is_array($groups) || $groups === []) {
                    $base['ok'] = true;
                    $base['message'] = __('Workers AI の今月の使用量を取得しました（0）');
                    $base['input_tokens'] = 0;
                    $base['output_tokens'] = 0;
                    $base['total_tokens'] = 0;
                    $base['requests'] = 0;

                    return $base;
                }

                $input = 0;
                $output = 0;
                $neurons = 0;
                $requests = 0;
                foreach ($groups as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $sum = is_array($row['sum'] ?? null) ? $row['sum'] : [];
                    $input += (int) ($sum['totalInputTokens'] ?? 0);
                    $output += (int) ($sum['totalOutputTokens'] ?? 0);
                    $neurons += (int) ($sum['totalNeurons'] ?? 0);
                    $requests += (int) ($row['count'] ?? $sum['requests'] ?? 0);
                }

                $base['ok'] = true;
                $base['message'] = __('Workers AI の今月の使用量を取得しました');
                $base['input_tokens'] = $input;
                $base['output_tokens'] = $output;
                $base['total_tokens'] = $input + $output;
                $base['requests'] = $requests;
                $base['neurons'] = $neurons > 0 ? $neurons : null;

                return $base;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        $base['message'] = $lastError !== ''
            ? mb_substr($lastError, 0, 300)
            : __('Workers AI の公式使用量を取得できませんでした。');

        return $base;
    }

    private function workersAiGraphqlQuery(string $dataset, bool $withNeurons, string $accountId, string $start, string $end): string
    {
        $sumFields = $withNeurons
            ? 'totalInputTokens totalOutputTokens totalNeurons'
            : 'totalInputTokens totalOutputTokens';
        $accountId = addslashes($accountId);
        $start = addslashes($start);
        $end = addslashes($end);

        return <<<GQL
{
  viewer {
    accounts(filter: {accountTag: "{$accountId}"}) {
      {$dataset}(
        limit: 1
        filter: {datetime_geq: "{$start}", datetime_lt: "{$end}"}
      ) {
        count
        sum { {$sumFields} }
      }
    }
  }
}
GQL;
    }

    private function accountIdFromEnv(): string
    {
        return self::normalizeAccountId((string) config('services.cloudflare_workers_ai.account_id', ''));
    }

    /** @param  array<string, mixed>  $payload */
    private function errorMessage(array $payload, string $body): string
    {
        $code = (int) ($payload['errors'][0]['code'] ?? 0);
        $detail = trim((string) ($payload['errors'][0]['message'] ?? ''));

        $hint = match ($code) {
            10000 => __('認証エラーです。API トークンに Workers AI の権限があるか、アカウント ID が合っているか確認してください。'),
            7003, 7000 => __('アカウント ID が違うようです。Cloudflare ダッシュボード右側の 32 桁のアカウント ID だけを貼り付けてください。'),
            5007, 5006 => __('モデル名が違うようです。@cf/ で始まるモデル名を確認してください。'),
            default => '',
        };

        if ($hint !== '' && $detail !== '') {
            return $hint.'（'.mb_substr($detail, 0, 120).'）';
        }
        if ($hint !== '') {
            return $hint;
        }
        if ($detail !== '') {
            return mb_substr($detail, 0, 300);
        }

        return mb_substr($body, 0, 300);
    }

    private function encodeModelPath(string $model): string
    {
        return str_replace('%2F', '/', rawurlencode($model));
    }

    private function maxTokensForRequest(int $maxTokens): int
    {
        $capped = max(64, min(2048, $maxTokens));
        if ($this->modelLikelyThinks()) {
            return max($capped, 1024);
        }

        return $capped;
    }

    private function modelLikelyThinks(): bool
    {
        $model = strtolower($this->model());

        return str_contains($model, 'qwen3')
            || str_contains($model, 'qwq')
            || str_contains($model, 'kimi')
            || str_contains($model, 'thinking')
            || str_contains($model, 'deepseek-r')
            || str_contains($model, 'reason');
    }

    /** @param  array<string, mixed>  $payload */
    private function extractText(array $payload): string
    {
        $result = $payload['result'] ?? null;
        $candidates = [];
        if (is_string($result)) {
            $candidates[] = $result;
        }
        if (is_array($result)) {
            $candidates[] = $result['response'] ?? null;
            $candidates[] = $result['text'] ?? null;
            $candidates[] = data_get($result, 'choices.0.message.content');
            $candidates[] = data_get($result, 'choices.0.text');
        }
        foreach ($candidates as $raw) {
            $text = $this->normalizeModelText($raw);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function normalizeModelText(mixed $raw): string
    {
        if (is_array($raw)) {
            $parts = [];
            foreach ($raw as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $parts[] = $item;
                } elseif (is_array($item) && isset($item['text'])) {
                    $parts[] = (string) $item['text'];
                }
            }
            $raw = implode('', $parts);
        }
        if (! is_string($raw)) {
            return '';
        }
        $text = trim($raw);
        $text = preg_replace('/<think>.*?<\/think>/is', '', $text) ?? $text;
        $text = preg_replace('/^<think>.*$/is', '', $text) ?? $text;

        return trim($text);
    }

    /** @param  array<string, mixed>  $payload */
    private function recordObservedTokens(array $payload): void
    {
        $tokens = (int) data_get($payload, 'result.usage.total_tokens', 0);
        if ($tokens < 1) {
            $tokens = (int) data_get($payload, 'result.usage.prompt_tokens', 0)
                + (int) data_get($payload, 'result.usage.completion_tokens', 0);
        }
        if ($tokens > 0) {
            app(IntegrationUsageService::class)->increment('workers_ai', 'tokens', $tokens);
        }
    }
}
