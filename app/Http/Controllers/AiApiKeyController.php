<?php

namespace App\Http\Controllers;

use App\Models\AiApiKey;
use App\Services\AiChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AiApiKeyController extends Controller
{
    use Concerns\RedirectsWithFlash;

    private const SETTINGS_PATH = '/settings?section=ai&tab=chat';

    public function store(Request $request)
    {
        $validator = $this->makeValidator($request);
        if ($validator->fails()) {
            return $this->redirectWithMessage(self::SETTINGS_PATH, $validator->errors()->first(), 'error');
        }

        AiApiKey::create($this->payload($request));

        return $this->redirectWithMessage(self::SETTINGS_PATH, 'AIチャット用APIキーを追加しました');
    }

    public function edit(int $id)
    {
        $key = AiApiKey::find($id);
        if (! $key) {
            return response()->json(['error' => 'APIキーが見つかりません'], 404);
        }

        return response()->json($key);
    }

    public function update(Request $request, int $id)
    {
        $key = AiApiKey::find($id);
        if (! $key) {
            return $this->redirectWithMessage(self::SETTINGS_PATH, 'APIキーが見つかりません', 'error');
        }

        $validator = $this->makeValidator($request, $key);
        if ($validator->fails()) {
            return $this->redirectWithMessage(self::SETTINGS_PATH, $validator->errors()->first(), 'error');
        }

        $updateData = $this->payload($request, $key);
        if ($request->has('current_daily_usage') && $request->input('current_daily_usage') !== '') {
            $updateData['current_daily_usage'] = (int) $request->input('current_daily_usage');
            $updateData['last_reset_date'] = now()->toDateString();
        }
        if ($request->has('current_monthly_usage') && $request->input('current_monthly_usage') !== '') {
            $updateData['current_monthly_usage'] = (int) $request->input('current_monthly_usage');
            $updateData['last_monthly_reset_date'] = now()->format('Y-m-01');
        }

        $key->update($updateData);

        return $this->redirectWithMessage(self::SETTINGS_PATH, 'AIチャット用APIキーを更新しました');
    }

    public function destroy(int $id)
    {
        $key = AiApiKey::find($id);
        if (! $key) {
            return $this->redirectWithMessage(self::SETTINGS_PATH, 'APIキーが見つかりません', 'error');
        }
        $key->delete();

        return $this->redirectWithMessage(self::SETTINGS_PATH, 'AIチャット用APIキーを削除しました');
    }

    public function resetUsage(int $id)
    {
        $key = AiApiKey::find($id);
        if (! $key) {
            return $this->redirectWithMessage(self::SETTINGS_PATH, 'APIキーが見つかりません', 'error');
        }

        $key->update([
            'current_daily_usage' => 0,
            'current_monthly_usage' => 0,
            'error_count' => 0,
            'last_error_at' => null,
            'last_reset_date' => now()->toDateString(),
            'last_monthly_reset_date' => now()->format('Y-m-01'),
        ]);

        return $this->redirectWithMessage(self::SETTINGS_PATH, '使用量をリセットしました');
    }

    public function test(Request $request)
    {
        $provider = strtolower((string) $request->input('provider', 'openai'));
        $apiKey = trim((string) $request->input('api_key'));
        $model = trim((string) $request->input('default_model', ''));
        if ($apiKey === '') {
            return response()->json(['ok' => false, 'message' => 'APIキーを入力してください'], 422);
        }
        if (! array_key_exists($provider, config('ai_chat.providers', []))) {
            return response()->json(['ok' => false, 'message' => 'プロバイダが不正です'], 422);
        }

        try {
            if ($provider === 'openai') {
                $model = $model !== '' ? $model : (string) config('ai_chat.providers.openai.default_model');
                $res = \Illuminate\Support\Facades\Http::withToken($apiKey)
                    ->timeout(20)
                    ->post((string) config('ai_chat.providers.openai.api_url'), [
                        'model' => $model,
                        'messages' => [
                            ['role' => 'user', 'content' => 'ping'],
                        ],
                        'max_tokens' => 5,
                    ]);
            } else {
                $model = $model !== '' ? $model : (string) config('ai_chat.providers.gemini.default_model');
                $url = rtrim((string) config('ai_chat.providers.gemini.api_url'), '/')
                    .'/models/'.$model.':generateContent?key='.urlencode($apiKey);
                $res = \Illuminate\Support\Facades\Http::timeout(20)->post($url, [
                    'contents' => [
                        ['role' => 'user', 'parts' => [['text' => 'ping']]],
                    ],
                ]);
            }

            if ($res->successful()) {
                return response()->json(['ok' => true, 'message' => '接続成功']);
            }

            return response()->json([
                'ok' => false,
                'message' => '接続失敗 (HTTP '.$res->status().')',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => '接続エラー: '.$e->getMessage()]);
        }
    }

    private function makeValidator(Request $request, ?AiApiKey $existing = null)
    {
        $providers = implode(',', array_keys(config('ai_chat.providers', [])));

        return Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'provider' => 'required|in:'.$providers,
            'plan' => 'required|in:free,paid',
            'api_key' => ($existing ? 'nullable' : 'required').'|string|max:500',
            'default_model' => 'nullable|string|max:80',
            'daily_limit' => 'nullable|integer|min:0',
            'monthly_limit' => 'nullable|integer|min:0',
            'priority' => 'nullable|integer|min:0',
            'notes' => 'nullable|string|max:500',
            'is_active' => 'nullable',
        ], [
            'name.required' => '識別名を入力してください',
            'provider.required' => 'プロバイダを選んでください',
            'api_key.required' => 'APIキーを入力してください',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, ?AiApiKey $existing = null): array
    {
        $provider = strtolower((string) $request->input('provider'));
        $model = trim((string) $request->input('default_model'));
        if ($model === '') {
            $model = (string) config('ai_chat.providers.'.$provider.'.default_model');
        }

        $data = [
            'name' => trim((string) $request->input('name')),
            'provider' => $provider,
            'plan' => $request->input('plan') === 'free' ? 'free' : 'paid',
            'default_model' => $model,
            'daily_limit' => $request->filled('daily_limit') ? (int) $request->input('daily_limit') : null,
            'monthly_limit' => $request->filled('monthly_limit') ? (int) $request->input('monthly_limit') : null,
            'priority' => (int) ($request->input('priority') ?? 0),
            'notes' => $request->filled('notes') ? trim((string) $request->input('notes')) : null,
            'is_active' => $request->boolean('is_active'),
        ];

        $apiKey = trim((string) $request->input('api_key'));
        if ($apiKey !== '') {
            $data['api_key'] = $apiKey;
        } elseif ($existing) {
            $data['api_key'] = $existing->api_key;
        }

        return $data;
    }
}
