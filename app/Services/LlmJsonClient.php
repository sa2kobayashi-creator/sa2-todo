<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class LlmJsonClient
{
    public function __construct(private AiLlmConfigService $llm) {}

    public function isReady(): bool
    {
        return $this->llm->isReady();
    }

    public function activeProvider(): string
    {
        return $this->llm->activeProvider();
    }

    public function activeProviderLabel(): string
    {
        return $this->activeProvider() === AiLlmConfigService::PROVIDER_GEMINI
            ? 'Gemini'
            : 'ChatGPT';
    }

    /**
     * @return array{decoded: array<string, mixed>, provider: string, raw: string}
     */
    public function completeJson(string $prompt, ?string $systemPrompt = null): array
    {
        if (! $this->isReady()) {
            throw new \InvalidArgumentException(__('AI（ChatGPT / Gemini）が未設定です。設定 → AI設定で有効化してください。'));
        }

        $provider = $this->activeProvider();
        $raw = $provider === AiLlmConfigService::PROVIDER_GEMINI
            ? $this->callGemini($prompt)
            : $this->callOpenAi($prompt, $systemPrompt);

        return [
            'decoded' => $this->decodeJsonObject($raw),
            'provider' => $provider,
            'raw' => $raw,
        ];
    }

    private function callOpenAi(string $prompt, ?string $systemPrompt = null): string
    {
        $apiKey = $this->llm->apiKeyFor(AiLlmConfigService::PROVIDER_OPENAI);
        $model = $this->llm->openaiModel();
        $res = Http::withToken($apiKey)
            ->timeout(45)
            ->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemPrompt ?: 'You are a precise JSON extractor. Output JSON only.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
            ]);

        if (! $res->successful()) {
            throw new \RuntimeException(__('ChatGPT API エラー: :msg', [
                'msg' => mb_substr($res->body(), 0, 240),
            ]));
        }

        $content = (string) data_get($res->json(), 'choices.0.message.content', '');
        if (trim($content) === '') {
            throw new \RuntimeException(__('ChatGPT から空の応答が返りました。'));
        }

        return $content;
    }

    private function callGemini(string $prompt): string
    {
        $apiKey = $this->llm->apiKeyFor(AiLlmConfigService::PROVIDER_GEMINI);
        $model = rawurlencode($this->llm->geminiModel());
        $res = Http::timeout(45)
            ->acceptJson()
            ->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=".urlencode($apiKey),
                [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0,
                        'responseMimeType' => 'application/json',
                    ],
                ]
            );

        if (! $res->successful()) {
            throw new \RuntimeException(__('Gemini API エラー: :msg', [
                'msg' => mb_substr($res->body(), 0, 240),
            ]));
        }

        $parts = data_get($res->json(), 'candidates.0.content.parts', []);
        $text = '';
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (is_array($part) && isset($part['text'])) {
                    $text .= (string) $part['text'];
                }
            }
        }
        if (trim($text) === '') {
            throw new \RuntimeException(__('Gemini から空の応答が返りました。'));
        }

        return $text;
    }

    /** @return array<string, mixed> */
    public function decodeJsonObject(string $raw): array
    {
        $trimmed = trim($raw);
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
            $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
        }

        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded)) {
            if (preg_match('/\{.*\}/s', $trimmed, $m)) {
                $decoded = json_decode($m[0], true);
            }
        }
        if (! is_array($decoded)) {
            throw new \RuntimeException(__('AIの応答をJSONとして解釈できませんでした。'));
        }

        return $decoded;
    }

    public function normalizeConfidence(mixed $value): string
    {
        $confidence = strtolower(trim((string) ($value ?? 'medium')));

        return in_array($confidence, ['high', 'medium', 'low'], true) ? $confidence : 'medium';
    }

    public function normalizeDate(mixed $value, string $fallback): string
    {
        $date = trim((string) ($value ?? ''));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : $fallback;
    }

    public function normalizeTime(mixed $value): ?string
    {
        $time = trim((string) ($value ?? ''));
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        return null;
    }
}
