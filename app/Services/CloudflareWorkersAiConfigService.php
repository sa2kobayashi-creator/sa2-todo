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
            'settings' => [
                'account_id' => self::normalizeAccountId((string) ($settings['account_id'] ?? '')),
                'model' => self::normalizeModel((string) ($settings['model'] ?? '')),
            ],
            'secrets' => $mergedSecrets,
        ]);
        $row->save();

        return $row->fresh() ?? $row;
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{ok: bool, text: string, message: string}
     */
    public function complete(array $messages, int $maxTokens = 512): array
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
                    'max_tokens' => max(64, min(2048, $maxTokens)),
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
        if ($text === '') {
            return ['ok' => false, 'text' => '', 'message' => __('空の応答でした。モデル名を確認してください。')];
        }

        return ['ok' => true, 'text' => $text, 'message' => __('応答を取得しました')];
    }

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array
    {
        $result = $this->complete([
            ['role' => 'system', 'content' => 'Reply with the word OK only.'],
            ['role' => 'user', 'content' => 'OK?'],
        ], 16);

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
            'has_api_token' => $row->hasSecret('api_token'),
            'api_token_masked' => $row->maskedSecret('api_token'),
            'last_tested_at' => $row->last_tested_at?->format('Y-m-d H:i'),
            'last_test_status' => $row->last_test_status,
            'last_test_message' => $row->last_test_message,
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

    /** @param  array<string, mixed>  $payload */
    private function extractText(array $payload): string
    {
        $result = $payload['result'] ?? null;
        if (is_string($result) && trim($result) !== '') {
            return trim($result);
        }
        if (! is_array($result)) {
            return '';
        }
        if (is_string($result['response'] ?? null) && trim((string) $result['response']) !== '') {
            return trim((string) $result['response']);
        }
        $content = $result['choices'][0]['message']['content'] ?? null;
        if (is_string($content)) {
            return trim($content);
        }

        return '';
    }
}
