<?php

namespace App\Http\Controllers;

use App\Services\EkispertConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EkispertSettingsController extends Controller
{
    use Concerns\RedirectsWithFlash;

    private const RETURN_TO = '/settings?section=enhance#ekispert-api-settings';

    public function __construct(private EkispertConfigService $ekispert) {}

    public function update(Request $request)
    {
        $this->ekispert->save(
            $request->boolean('enabled'),
            ['base_url' => (string) $request->input('base_url', '')],
            ['api_key' => (string) $request->input('api_key', '')]
        );

        return $this->redirectWithMessage(self::RETURN_TO, __('駅すぱあと設定を保存しました'));
    }

    public function test(): JsonResponse
    {
        $result = $this->ekispert->testConnection();
        $this->ekispert->recordTestResult($result['ok'], $result['message']);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
