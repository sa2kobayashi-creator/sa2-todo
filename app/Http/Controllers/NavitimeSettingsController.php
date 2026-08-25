<?php

namespace App\Http\Controllers;

use App\Services\NavitimeConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NavitimeSettingsController extends Controller
{
    use Concerns\RedirectsWithFlash;

    private const RETURN_TO = '/settings?section=enhance#navitime-api-settings';

    public function __construct(private NavitimeConfigService $navitime) {}

    public function update(Request $request)
    {
        $mode = NavitimeConfigService::normalizeMode((string) $request->input('mode', ''));
        $baseUrl = NavitimeConfigService::normalizeBaseUrl((string) $request->input('base_url', ''));

        if ($mode === NavitimeConfigService::MODE_DIRECT && $baseUrl === '') {
            return $this->redirectWithMessage(
                self::RETURN_TO,
                __('直接契約のときは、契約時に案内されたベース URL（https://{HOST}/{CID}/v1）を入力してください。'),
                'error'
            );
        }

        $this->navitime->save(
            $request->boolean('enabled'),
            [
                'mode' => $mode,
                'route_host' => (string) $request->input('route_host', ''),
                'node_host' => (string) $request->input('node_host', ''),
                'base_url' => (string) $request->input('base_url', ''),
                'auth_header' => (string) $request->input('auth_header', ''),
            ],
            [
                'api_key' => (string) $request->input('api_key', ''),
            ]
        );

        return $this->redirectWithMessage(self::RETURN_TO, __('NAVITIME 設定を保存しました'));
    }

    public function test(): JsonResponse
    {
        $result = $this->navitime->testConnection();
        $this->navitime->recordTestResult($result['ok'], $result['message']);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
