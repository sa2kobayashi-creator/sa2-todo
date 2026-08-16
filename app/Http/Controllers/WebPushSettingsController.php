<?php

namespace App\Http\Controllers;

use App\Services\WebPushConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebPushSettingsController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(private WebPushConfigService $webPush) {}

    public function update(Request $request)
    {
        $this->webPush->saveConfig(
            $request->boolean('enabled'),
            [
                'subject' => (string) $request->input('subject', ''),
                'public_key' => (string) $request->input('public_key', ''),
                'private_key' => (string) $request->input('private_key', ''),
            ]
        );

        return $this->redirectWithMessage(
            '/settings?section=integration#web-push',
            __('Web Push 通知設定を保存しました。')
        );
    }

    public function test(): JsonResponse
    {
        $result = $this->webPush->testConfig();
        $this->webPush->recordTestResult($result['ok'], $result['message']);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function generate(): JsonResponse
    {
        try {
            $keys = $this->webPush->generateKeys();
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => __('VAPID 鍵の生成に失敗しました: :msg', ['msg' => mb_substr($e->getMessage(), 0, 200)]),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'publicKey' => $keys['publicKey'],
            'privateKey' => $keys['privateKey'],
            'message' => __('鍵を生成しました。入力欄に反映したので「有効にする」をオンにして保存してください。'),
        ]);
    }
}
