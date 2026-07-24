<?php

namespace App\Http\Controllers;

use App\Services\AiLlmConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiLlmSettingsController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(private AiLlmConfigService $llm) {}

    public function update(Request $request)
    {
        $enabled = $request->boolean('enabled');
        $this->llm->save(
            $enabled,
            [
                'active_provider' => (string) $request->input('active_provider', AiLlmConfigService::PROVIDER_OPENAI),
                'openai_model' => (string) $request->input('openai_model', 'gpt-4o-mini'),
                'gemini_model' => (string) $request->input('gemini_model', 'gemini-2.0-flash'),
            ],
            [
                'openai_api_key' => (string) $request->input('openai_api_key', ''),
                'gemini_api_key' => (string) $request->input('gemini_api_key', ''),
            ]
        );

        return $this->redirectWithMessage(
            '/settings?section=ai#ai-llm-settings',
            __('LLM設定を保存しました')
        );
    }

    public function test(Request $request): JsonResponse
    {
        $provider = (string) $request->input('provider', '');
        if (! in_array($provider, [AiLlmConfigService::PROVIDER_OPENAI, AiLlmConfigService::PROVIDER_GEMINI], true)) {
            $provider = $this->llm->activeProvider();
        }

        $result = $this->llm->testConnection($provider);
        $this->llm->recordTestResult($result['ok'], $result['message']);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
