<?php

namespace App\Http\Controllers;

use App\Models\TranslationApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class TranslationApiKeyController extends Controller
{
    use Concerns\RedirectsWithFlash;

    private const SETTINGS_PATH = '/settings?section=translation';

    public function store(Request $request)
    {
        $validator = $this->makeValidator($request);
        if ($validator->fails()) {
            return $this->redirectWithMessage(self::SETTINGS_PATH, $validator->errors()->first(), 'error');
        }

        TranslationApiKey::create($this->payload($request));

        return $this->redirectWithMessage(self::SETTINGS_PATH, '翻訳APIキーを追加しました');
    }

    public function update(Request $request, int $id)
    {
        $key = TranslationApiKey::find($id);
        if (! $key) {
            return $this->redirectWithMessage(self::SETTINGS_PATH, 'APIキーが見つかりません', 'error');
        }

        $validator = $this->makeValidator($request, $key);
        if ($validator->fails()) {
            return $this->redirectWithMessage(self::SETTINGS_PATH, $validator->errors()->first(), 'error');
        }

        $key->update($this->payload($request, $key));

        return $this->redirectWithMessage(self::SETTINGS_PATH, '翻訳APIキーを更新しました');
    }

    public function destroy(int $id)
    {
        $key = TranslationApiKey::find($id);
        if (! $key) {
            return $this->redirectWithMessage(self::SETTINGS_PATH, 'APIキーが見つかりません', 'error');
        }
        $key->delete();

        return $this->redirectWithMessage(self::SETTINGS_PATH, '翻訳APIキーを削除しました');
    }

    /**
     * APIキーの疎通確認（EN→JA で "Hello, world!" を翻訳）。
     */
    public function test(Request $request)
    {
        $apiKey = trim((string) $request->input('api_key'));
        if ($apiKey === '') {
            return response()->json(['ok' => false, 'message' => 'APIキーを入力してください'], 422);
        }

        $apiUrl = $request->input('api_url')
            ?: (str_contains($apiKey, ':fx')
                ? 'https://api-free.deepl.com/v2/translate'
                : 'https://api.deepl.com/v2/translate');

        try {
            $response = Http::withHeaders([
                'Authorization' => 'DeepL-Auth-Key '.$apiKey,
            ])->timeout(10)->asForm()->post($apiUrl, [
                'text' => 'Hello, world!',
                'source_lang' => 'EN',
                'target_lang' => 'JA',
            ]);

            if ($response->successful()) {
                $translated = $response->json()['translations'][0]['text'] ?? null;

                return response()->json([
                    'ok' => true,
                    'message' => '接続に成功しました',
                    'translated' => $translated,
                ]);
            }

            $message = $response->json()['message'] ?? ('HTTP '.$response->status());

            return response()->json(['ok' => false, 'message' => '接続に失敗しました: '.$message], 200);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => '接続に失敗しました: '.$e->getMessage()], 200);
        }
    }

    private function makeValidator(Request $request, ?TranslationApiKey $existing = null): \Illuminate\Validation\Validator
    {
        $rules = [
            'name' => 'required|string|max:255',
            'api_key' => ($existing ? 'nullable' : 'required').'|string|min:10',
            'api_url' => 'nullable|url|max:500',
            'daily_limit' => 'nullable|integer|min:0',
            'monthly_limit' => 'nullable|integer|min:0',
            'priority' => 'nullable|integer|min:0',
            'notes' => 'nullable|string|max:1000',
        ];

        return Validator::make($request->all(), $rules, [
            'name.required' => '識別名を入力してください',
            'api_key.required' => 'APIキーを入力してください',
            'api_key.min' => 'APIキーが短すぎます',
            'api_url.url' => 'APIエンドポイントURLの形式が正しくありません',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, ?TranslationApiKey $existing = null): array
    {
        $data = [
            'name' => $request->input('name'),
            'provider' => 'deepl',
            'api_url' => $request->input('api_url') ?: null,
            'daily_limit' => $request->filled('daily_limit') ? (int) $request->input('daily_limit') : null,
            'monthly_limit' => $request->filled('monthly_limit') ? (int) $request->input('monthly_limit') : null,
            'priority' => (int) $request->input('priority', 0),
            'is_active' => $request->boolean('is_active'),
            'notes' => $request->input('notes') ?: null,
        ];

        // 編集時、APIキーが空欄なら既存の値を維持する。
        $apiKey = trim((string) $request->input('api_key'));
        if ($apiKey !== '' || ! $existing) {
            $data['api_key'] = $apiKey;
        }

        return $data;
    }
}
