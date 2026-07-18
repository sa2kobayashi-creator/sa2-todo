<?php

namespace App\Services;

use App\Models\AiApiKey;
use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AiChatService
{
    public function estimateTokens(string $text): int
    {
        // 日本語混在の簡易見積（おおよそ文字数）
        return max(1, (int) ceil(mb_strlen($text) * 0.6));
    }

    /** @return list<array{id:int,title:string,provider:string,model:string,updatedAt:string,preview:?string}> */
    public function listConversations(int $userId): array
    {
        return AiConversation::query()
            ->where('user_id', $userId)
            ->with(['messages' => fn ($q) => $q->where('role', 'user')->orderByDesc('id')->limit(1)])
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get()
            ->map(function (AiConversation $c) {
                $preview = $c->messages->first()?->content;

                return [
                    'id' => $c->id,
                    'title' => $c->title,
                    'provider' => $c->provider,
                    'model' => $c->model,
                    'updatedAt' => $c->updated_at?->toIso8601String(),
                    'preview' => $preview ? Str::limit($preview, 60) : null,
                ];
            })
            ->all();
    }

    /** @return array{id:int,title:string,provider:string,model:string,messages:list<array{id:int,role:string,content:string,createdAt:?string}>} */
    public function getConversation(int $userId, int $conversationId): array
    {
        $conversation = $this->ownedConversation($userId, $conversationId);
        $messages = $conversation->messages()
            ->orderBy('id')
            ->get()
            ->map(fn (AiMessage $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'createdAt' => $m->created_at?->toIso8601String(),
            ])
            ->all();

        return [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'provider' => $conversation->provider,
            'model' => $conversation->model,
            'messages' => $messages,
        ];
    }

    public function createConversation(int $userId, string $provider, ?string $model = null): AiConversation
    {
        $provider = $this->assertProvider($provider);
        $model = $this->resolveModel($provider, $model);
        $key = $this->pickKey($provider);

        return AiConversation::create([
            'user_id' => $userId,
            'title' => '新しい相談',
            'provider' => $provider,
            'model' => $model,
            'ai_api_key_id' => $key?->id,
        ]);
    }

    public function deleteConversation(int $userId, int $conversationId): bool
    {
        $conversation = AiConversation::query()
            ->where('user_id', $userId)
            ->where('id', $conversationId)
            ->first();
        if (! $conversation) {
            return false;
        }
        $conversation->delete();

        return true;
    }

    /**
     * ユーザーメッセージを保存し、ストリーミングで応答する。
     * $onDelta(string $chunk) が呼ばれ、最後に assistant メッセージを返す。
     */
    public function streamReply(int $userId, int $conversationId, string $userMessage, callable $onDelta): AiMessage
    {
        $userMessage = trim($userMessage);
        $maxChars = (int) config('ai_chat.max_user_message_chars', 8000);
        if ($userMessage === '') {
            throw new \InvalidArgumentException('メッセージを入力してください');
        }
        if (mb_strlen($userMessage) > $maxChars) {
            throw new \InvalidArgumentException('メッセージは'.$maxChars.'文字以内にしてください');
        }

        $conversation = $this->ownedConversation($userId, $conversationId);
        $provider = $this->assertProvider($conversation->provider);
        $model = $this->resolveModel($provider, $conversation->model);

        $key = null;
        if ($conversation->ai_api_key_id) {
            $key = AiApiKey::query()
                ->where('id', $conversation->ai_api_key_id)
                ->where('is_active', true)
                ->first();
        }
        $key = $key ?: $this->pickKey($provider);
        if (! $key) {
            throw new \InvalidArgumentException(
                'AIチャット用APIキーがありません。設定 > AI設定 から OpenAI / Gemini のキーを登録してください。'
            );
        }

        $userTokens = $this->estimateTokens($userMessage);
        $key->resetUsageIfNeeded();
        if ($key->daily_limit !== null && $key->current_daily_usage + $userTokens > $key->daily_limit) {
            throw new \InvalidArgumentException('日次利用上限に達しています（'.$key->name.'）');
        }
        if ($key->monthly_limit !== null && $key->current_monthly_usage + $userTokens > $key->monthly_limit) {
            throw new \InvalidArgumentException('月次利用上限に達しています（'.$key->name.'）');
        }

        $isFirst = ! $conversation->messages()->exists();
        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessage,
            'token_estimate' => $userTokens,
        ]);

        if ($isFirst) {
            $conversation->title = Str::limit(preg_replace('/\s+/u', ' ', $userMessage) ?: '新しい相談', 40);
        }
        $conversation->model = $model;
        $conversation->ai_api_key_id = $key->id;
        $conversation->touch();
        $conversation->save();

        $history = $this->buildHistory($conversation);
        try {
            $assistantText = $provider === 'openai'
                ? $this->streamOpenAi($key, $model, $history, $onDelta)
                : $this->streamGemini($key, $model, $history, $onDelta);
            $key->resetErrorCount();
        } catch (\Throwable $e) {
            $key->recordError();
            throw $e;
        }

        $assistantText = trim($assistantText);
        if ($assistantText === '') {
            throw new \RuntimeException('AIからの応答が空でした。モデルやキーを確認してください。');
        }

        $assistantTokens = $this->estimateTokens($assistantText);
        $key->incrementUsage($userTokens + $assistantTokens);

        $message = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $assistantText,
            'token_estimate' => $assistantTokens,
        ]);
        $conversation->touch();

        return $message;
    }

    /** @return list<array{value:string,label:string,models:array<string,string>,defaultModel:string}> */
    public function providerOptions(): array
    {
        $out = [];
        foreach (config('ai_chat.providers', []) as $value => $meta) {
            $out[] = [
                'value' => $value,
                'label' => $meta['label'] ?? $value,
                'models' => $meta['models'] ?? [],
                'defaultModel' => $meta['default_model'] ?? array_key_first($meta['models'] ?? []) ?? '',
            ];
        }

        return $out;
    }

    public function hasAnyActiveKey(): bool
    {
        return AiApiKey::query()->where('is_active', true)->exists();
    }

    private function ownedConversation(int $userId, int $conversationId): AiConversation
    {
        $conversation = AiConversation::query()
            ->where('user_id', $userId)
            ->where('id', $conversationId)
            ->first();
        if (! $conversation) {
            throw new \InvalidArgumentException('会話が見つかりません');
        }

        return $conversation;
    }

    private function assertProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (! array_key_exists($provider, config('ai_chat.providers', []))) {
            throw new \InvalidArgumentException('対応プロバイダは ChatGPT (OpenAI) / Gemini です');
        }

        return $provider;
    }

    private function resolveModel(string $provider, ?string $model): string
    {
        $models = config('ai_chat.providers.'.$provider.'.models', []);
        $default = (string) config('ai_chat.providers.'.$provider.'.default_model', array_key_first($models) ?: '');
        $model = trim((string) $model);
        if ($model !== '' && array_key_exists($model, $models)) {
            return $model;
        }

        return $default;
    }

    private function pickKey(string $provider): ?AiApiKey
    {
        $keys = AiApiKey::getAvailableKeys($provider);
        foreach ($keys as $key) {
            $key->resetUsageIfNeeded();
        }

        return $keys->first();
    }

    /** @return list<array{role:string,content:string}> */
    private function buildHistory(AiConversation $conversation): array
    {
        $limit = max(4, (int) config('ai_chat.max_history_messages', 40));
        $rows = $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->values();

        $history = [
            [
                'role' => 'system',
                'content' => 'あなたは Sa2 ToDo アプリの相談相手です。日本語で簡潔かつ親切に答えてください。家計・予定・生活の相談に役立つアドバイスをします。わからないことは推測で断定せず、確認を促してください。',
            ],
        ];
        foreach ($rows as $row) {
            $history[] = [
                'role' => $row->role,
                'content' => $row->content,
            ];
        }

        return $history;
    }

    /** @param list<array{role:string,content:string}> $history */
    private function streamOpenAi(AiApiKey $key, string $model, array $history, callable $onDelta): string
    {
        $url = (string) config('ai_chat.providers.openai.api_url');
        $response = Http::withToken($key->api_key)
            ->withHeaders(['Accept' => 'text/event-stream'])
            ->withOptions(['stream' => true, 'read_timeout' => 120])
            ->post($url, [
                'model' => $model,
                'messages' => $history,
                'stream' => true,
                'temperature' => 0.7,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException($this->httpErrorMessage('OpenAI', $response->status(), $response->body()));
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $assistant = '';
        while (! $body->eof()) {
            $buffer .= $body->read(1024);
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }
                $data = trim(substr($line, 5));
                if ($data === '[DONE]') {
                    break 2;
                }
                $json = json_decode($data, true);
                $delta = $json['choices'][0]['delta']['content'] ?? '';
                if ($delta !== '') {
                    $assistant .= $delta;
                    $onDelta($delta);
                }
            }
        }

        return $assistant;
    }

    /** @param list<array{role:string,content:string}> $history */
    private function streamGemini(AiApiKey $key, string $model, array $history, callable $onDelta): string
    {
        $base = rtrim((string) config('ai_chat.providers.gemini.api_url'), '/');
        $url = $base.'/models/'.$model.':streamGenerateContent?alt=sse&key='.urlencode($key->api_key);

        $contents = [];
        $system = null;
        foreach ($history as $item) {
            if ($item['role'] === 'system') {
                $system = $item['content'];
                continue;
            }
            $contents[] = [
                'role' => $item['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $item['content']]],
            ];
        }

        $payload = ['contents' => $contents];
        if ($system) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $system]],
            ];
        }

        $response = Http::withHeaders(['Accept' => 'text/event-stream', 'Content-Type' => 'application/json'])
            ->withOptions(['stream' => true, 'read_timeout' => 120])
            ->post($url, $payload);

        if ($response->failed()) {
            throw new \RuntimeException($this->httpErrorMessage('Gemini', $response->status(), $response->body()));
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $assistant = '';
        while (! $body->eof()) {
            $buffer .= $body->read(1024);
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }
                $data = trim(substr($line, 5));
                if ($data === '' || $data === '[DONE]') {
                    continue;
                }
                $json = json_decode($data, true);
                $delta = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if ($delta !== '') {
                    $assistant .= $delta;
                    $onDelta($delta);
                }
            }
        }

        return $assistant;
    }

    private function httpErrorMessage(string $label, int $status, string $body): string
    {
        $snippet = Str::limit(trim(strip_tags($body)), 180);
        if ($status === 401 || $status === 403) {
            return $label.' APIキーが無効か権限がありません';
        }
        if ($status === 429) {
            return $label.' の利用上限に達しました。しばらくして再試行してください';
        }

        return $label.' APIエラー (HTTP '.$status.')'.($snippet !== '' ? ': '.$snippet : '');
    }
}
