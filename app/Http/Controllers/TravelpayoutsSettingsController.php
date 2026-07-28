<?php

namespace App\Http\Controllers;

use App\Services\TravelpayoutsConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TravelpayoutsSettingsController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(private TravelpayoutsConfigService $travelpayouts) {}

    public function update(Request $request)
    {
        $this->travelpayouts->saveConfig(
            $request->boolean('enabled'),
            [
                'token' => (string) $request->input('token', ''),
                'project_id' => (string) $request->input('project_id', ''),
                'prefer_airline' => (string) $request->input('prefer_airline', ''),
                'direct_only' => $request->boolean('direct_only'),
                'market_php' => (string) $request->input('market_php', ''),
                'market_jpy' => (string) $request->input('market_jpy', ''),
            ]
        );

        return $this->redirectWithMessage(
            '/settings?section=enhance#travelpayouts-api-settings',
            __('Travelpayouts 設定を保存しました。')
        );
    }

    public function test(): JsonResponse
    {
        $result = $this->travelpayouts->testConnection();
        $this->travelpayouts->recordTestResult($result['ok'], $result['message']);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
