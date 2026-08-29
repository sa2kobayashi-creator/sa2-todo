<?php

namespace App\Http\Controllers;

use App\Services\GoogleMapsConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoogleMapsSettingsController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(private GoogleMapsConfigService $googleMaps) {}

    public function update(Request $request)
    {
        $this->googleMaps->saveConfig(
            $request->boolean('enabled'),
            [
                'api_key' => (string) $request->input('api_key', ''),
                'referrer_restriction_confirmed' => $request->boolean('referrer_restriction_confirmed'),
            ]
        );

        return $this->redirectWithMessage(
            '/settings?section=enhance#google-maps-api-settings',
            __('Google Maps 設定を保存しました。')
        );
    }

    public function test(): JsonResponse
    {
        $result = $this->googleMaps->testConnection();
        $this->googleMaps->recordTestResult($result['ok'], $result['message']);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
