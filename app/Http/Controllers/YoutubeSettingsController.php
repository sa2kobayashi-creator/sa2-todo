<?php

namespace App\Http\Controllers;

use App\Services\YoutubeVideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YoutubeSettingsController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(private YoutubeVideoService $youtube) {}

    public function update(Request $request)
    {
        $this->youtube->saveConfig(
            $request->boolean('enabled'),
            ['api_key' => (string) $request->input('api_key', '')]
        );

        return $this->redirectWithMessage(
            '/settings?section=ai#youtube-api-settings',
            __('YouTube検索設定を保存しました。')
        );
    }

    public function test(): JsonResponse
    {
        $result = $this->youtube->testConnection();
        $this->youtube->recordTestResult($result['ok'], $result['message']);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
