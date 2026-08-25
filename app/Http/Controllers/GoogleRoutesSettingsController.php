<?php

namespace App\Http\Controllers;

use App\Services\GoogleRoutesConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoogleRoutesSettingsController extends Controller
{
    use Concerns\RedirectsWithFlash;

    private const RETURN_TO = '/settings?section=enhance#google-routes-api-settings';

    public function __construct(private GoogleRoutesConfigService $routes) {}

    public function update(Request $request)
    {
        $this->routes->save(
            $request->boolean('enabled'),
            ['api_key' => (string) $request->input('api_key', '')]
        );

        return $this->redirectWithMessage(self::RETURN_TO, __('Google Maps Routes API 設定を保存しました'));
    }

    public function test(): JsonResponse
    {
        $result = $this->routes->testConnection();
        $this->routes->recordTestResult($result['ok'], $result['message']);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
