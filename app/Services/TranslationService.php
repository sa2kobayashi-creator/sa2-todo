<?php

namespace App\Services;

use App\Models\TranslationApiKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranslationService
{
    private string $provider;

    private ?string $apiKey = null;

    private ?string $apiUrl = null;

    private bool $useDatabaseKeys = false;

    private ?TranslationApiKey $currentApiKeyModel = null;

    public function __construct()
    {
        $this->provider = config('services.translation.provider', 'deepl');

        // データベースにAPIキーが存在する場合はそれを優先し、なければ .env を使用する。
        $this->useDatabaseKeys = TranslationApiKey::where('is_active', true)
            ->where('provider', $this->provider)
            ->exists();

        if ($this->useDatabaseKeys) {
            $this->currentApiKeyModel = $this->getAvailableApiKey();
            if ($this->currentApiKeyModel) {
                $this->apiKey = $this->currentApiKeyModel->api_key;
                $this->apiUrl = $this->currentApiKeyModel->api_url ?: $this->defaultApiUrl($this->currentApiKeyModel->api_key);
            }
        } else {
            $this->apiKey = config('services.translation.api_key');
            $this->apiUrl = config('services.translation.api_url');
        }
    }

    /**
     * 翻訳機能が利用可能か（有効なAPIキーが設定されているか）を返す。
     */
    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * テキストを翻訳する（24時間キャッシュ付き）。
     */
    public function translate(string $text, string $sourceLang, string $targetLang): ?string
    {
        if (trim($text) === '') {
            return $text;
        }
        if ($sourceLang === $targetLang) {
            return $text;
        }

        $cacheKey = 'translation:'.md5($text.'|'.$sourceLang.'|'.$targetLang);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $result = $this->fetchTranslation($text, $sourceLang, $targetLang);
        if ($result !== null) {
            Cache::put($cacheKey, $result, config('services.translation.cache_ttl', 86400));
        }

        return $result;
    }

    /**
     * リトライ／フェイルオーバー付きで翻訳を取得する。
     */
    private function fetchTranslation(string $text, string $sourceLang, string $targetLang): ?string
    {
        $maxRetries = $this->useDatabaseKeys ? 3 : 1;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                $result = $this->translateWithDeepL($text, $sourceLang, $targetLang);

                if ($result !== null) {
                    if ($this->currentApiKeyModel) {
                        $this->currentApiKeyModel->incrementUsage(mb_strlen($text));
                        $this->currentApiKeyModel->resetErrorCount();
                    }

                    return $result;
                }

                if ($this->useDatabaseKeys && $this->switchToNextApiKey()) {
                    $attempt++;

                    continue;
                }

                return null;
            } catch (\Throwable $e) {
                $message = $e->getMessage();
                $isQuotaError = stripos($message, 'quota') !== false
                    || stripos($message, 'limit') !== false
                    || stripos($message, '制限') !== false;

                Log::error('Translation failed', ['message' => $message]);

                if ($this->currentApiKeyModel) {
                    $this->currentApiKeyModel->recordError();
                }

                if ($isQuotaError && $this->useDatabaseKeys && $this->switchToNextApiKey()) {
                    $attempt++;

                    continue;
                }

                return null;
            }
        }

        return null;
    }

    /**
     * DeepL API を呼び出して翻訳する。
     */
    private function translateWithDeepL(string $text, string $sourceLang, string $targetLang): ?string
    {
        if (! $this->apiKey) {
            Log::warning('DeepL API key not configured');

            return null;
        }

        $sourceLangCode = $this->convertToDeepLLangCode($sourceLang);
        $targetLangCode = $this->convertToDeepLLangCode($targetLang);
        $apiUrl = $this->apiUrl ?: $this->defaultApiUrl($this->apiKey);

        $response = Http::withHeaders([
            'Authorization' => 'DeepL-Auth-Key '.$this->apiKey,
        ])->timeout(15)->asForm()->post($apiUrl, [
            'text' => $text,
            'source_lang' => $sourceLangCode,
            'target_lang' => $targetLangCode,
        ]);

        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['translations'][0]['text'])) {
                return $data['translations'][0]['text'];
            }

            Log::warning('Unexpected DeepL response format', ['response' => $data]);

            return null;
        }

        $errorBody = $response->body();
        $errorData = json_decode($errorBody, true);
        $errorMessage = $errorData['message'] ?? 'Unknown error';

        Log::error('DeepL API error response', [
            'status' => $response->status(),
            'message' => $errorMessage,
        ]);

        // クォータ超過（HTTP 456）は例外を投げて次のキーへ切り替える。
        if ($response->status() === 456
            || stripos($errorMessage, 'quota') !== false
            || stripos($errorMessage, 'limit') !== false) {
            throw new \RuntimeException('Translation API quota/limit exceeded');
        }

        return null;
    }

    /**
     * 使用可能なAPIキーを取得する。
     */
    private function getAvailableApiKey(): ?TranslationApiKey
    {
        $key = TranslationApiKey::getAvailableKeys($this->provider)->first();
        $key?->resetUsageIfNeeded();

        return $key;
    }

    /**
     * 次に使用可能なAPIキーへ切り替える。
     */
    private function switchToNextApiKey(): bool
    {
        if (! $this->currentApiKeyModel) {
            return false;
        }

        $currentId = $this->currentApiKeyModel->id;
        $next = TranslationApiKey::getAvailableKeys($this->provider)
            ->firstWhere('id', '!=', $currentId);

        if (! $next) {
            return false;
        }

        $this->currentApiKeyModel = $next;
        $this->apiKey = $next->api_key;
        $this->apiUrl = $next->api_url ?: $this->defaultApiUrl($next->api_key);

        return true;
    }

    /**
     * APIキーの形式から無料版/有料版のエンドポイントを判定する。
     */
    private function defaultApiUrl(string $apiKey): string
    {
        return str_contains($apiKey, ':fx')
            ? 'https://api-free.deepl.com/v2/translate'
            : 'https://api.deepl.com/v2/translate';
    }

    /**
     * アプリのロケール文字列を DeepL の言語コードへ変換する。
     */
    private function convertToDeepLLangCode(string $locale): string
    {
        $map = [
            'ja' => 'JA',
            'en' => 'EN',
            'zh-CN' => 'ZH-HANS',
            'zh-HK' => 'ZH-HANT',
            'ko' => 'KO',
        ];

        return $map[$locale] ?? strtoupper($locale);
    }
}
