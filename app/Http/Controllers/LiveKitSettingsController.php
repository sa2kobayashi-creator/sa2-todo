<?php

namespace App\Http\Controllers;

use App\Services\LiveKitConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveKitSettingsController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(private LiveKitConfigService $livekit) {}

    public function update(Request $request)
    {
        $this->livekit->saveConfig(
            $request->boolean('enabled'),
            [
                'url' => (string) $request->input('url', ''),
                'api_key' => (string) $request->input('api_key', ''),
                'api_secret' => (string) $request->input('api_secret', ''),
            ]
        );

        return $this->redirectWithMessage(
            '/settings?section=integration#livekit-call',
            __('LiveKit 通話設定を保存しました。')
        );
    }

    public function test(): JsonResponse
    {
        $result = $this->livekit->testConnection();
        $this->livekit->recordTestResult($result['ok'], $result['message']);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
