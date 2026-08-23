<?php

namespace App\Http\Controllers;

use App\Services\GoogleCalendarConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoogleCalendarOauthSettingsController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(private GoogleCalendarConfigService $googleCalendar) {}

    public function update(Request $request)
    {
        $this->googleCalendar->saveConfig(
            $request->boolean('enabled'),
            [
                'client_id' => (string) $request->input('client_id', ''),
                'client_secret' => (string) $request->input('client_secret', ''),
                'redirect_uri' => (string) $request->input('redirect_uri', ''),
            ]
        );

        return $this->redirectWithMessage(
            '/settings?section=enhance#google-calendar-oauth-settings',
            __('Google Calendar OAuth 設定を保存しました。')
        );
    }

    public function test(): JsonResponse
    {
        $result = $this->googleCalendar->testConnection();
        $this->googleCalendar->recordTestResult($result['ok'], $result['message']);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
