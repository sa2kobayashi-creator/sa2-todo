<?php

namespace App\Http\Controllers;

use App\Services\CloudflareWorkersAiConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkersAiSettingsController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(private CloudflareWorkersAiConfigService $workersAi) {}

    public function update(Request $request)
    {
        $rawAccountId = trim((string) $request->input('account_id', ''));
        if ($rawAccountId !== '' && CloudflareWorkersAiConfigService::normalizeAccountId($rawAccountId) === '') {
            return $this->redirectWithMessage(
                '/settings?section=ai#workers-ai-settings',
                __('Cloudflare アカウント ID は 32 桁の英数字です。ダッシュボードのアカウント ID だけを貼り付けてください。'),
                'error'
            );
        }

        $this->workersAi->save(
            $request->boolean('enabled'),
            [
                'account_id' => (string) $request->input('account_id', ''),
                'model' => (string) $request->input('model', CloudflareWorkersAiConfigService::DEFAULT_MODEL),
            ],
            [
                'api_token' => (string) $request->input('api_token', ''),
            ]
        );

        return $this->redirectWithMessage(
            '/settings?section=ai#workers-ai-settings',
            __('Workers AI 設定を保存しました')
        );
    }

    public function test(): JsonResponse
    {
        $result = $this->workersAi->testConnection();
        $this->workersAi->recordTestResult($result['ok'], $result['message']);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
