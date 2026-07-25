<?php

namespace App\Http\Controllers;

use App\Services\EnhanceConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnhanceSettingsController extends Controller
{
    use Concerns\RedirectsWithFlash;

    private const SETTINGS_PATH = '/settings?section=enhance';

    public function __construct(private EnhanceConfigService $enhance) {}

    public function updateActive(Request $request)
    {
        $provider = (string) $request->input('active_provider', EnhanceConfigService::PROVIDER_STABILITY);
        $this->enhance->saveActiveProvider($provider);

        return $this->redirectWithMessage(
            self::SETTINGS_PATH.'#enhance-active',
            __('使用する鮮明化エンジンを保存しました（:name）。', [
                'name' => $this->enhance->providerLabel($provider),
            ])
        );
    }

    public function updateProvider(Request $request, string $provider)
    {
        if (! in_array($provider, $this->enhance->providers(), true)) {
            return $this->redirectWithMessage(self::SETTINGS_PATH, __('不正なプロバイダです'), 'error');
        }

        $enabled = $request->boolean('enabled');
        [$settings, $secrets] = match ($provider) {
            EnhanceConfigService::PROVIDER_STABILITY => [
                [
                    'mode' => in_array($request->input('mode'), ['fast', 'conservative', 'creative'], true)
                        ? (string) $request->input('mode')
                        : 'conservative',
                    'output_format' => in_array($request->input('output_format'), ['jpeg', 'png', 'webp'], true)
                        ? (string) $request->input('output_format')
                        : 'jpeg',
                    'default_prompt' => (string) $request->input('default_prompt', ''),
                ],
                [
                    'api_key' => (string) $request->input('api_key', ''),
                ],
            ],
            EnhanceConfigService::PROVIDER_REALESRGAN => [
                [
                    'binary_path' => trim((string) $request->input('binary_path', '')),
                    'model' => in_array($request->input('model'), [
                        'realesrgan-x4plus',
                        'realesrgan-x4plus-anime',
                        'realesrnet-x4plus',
                        'realesr-animevideov3',
                    ], true)
                        ? (string) $request->input('model')
                        : 'realesrgan-x4plus',
                    'scale' => max(2, min(4, (int) $request->input('scale', 2))),
                    'gpu_id' => (string) $request->input('gpu_id', '0'),
                    'tile_size' => max(0, (int) $request->input('tile_size', 64)),
                    'timeout_seconds' => max(30, min(3600, (int) $request->input('timeout_seconds', 600))),
                    'output_format' => in_array($request->input('output_format'), ['jpg', 'png', 'webp'], true)
                        ? (string) $request->input('output_format')
                        : 'png',
                ],
                [],
            ],
            EnhanceConfigService::PROVIDER_SWINIR => [
                [
                    'endpoint' => rtrim(trim((string) $request->input('endpoint', '')), '/'),
                    'scale' => 4,
                    'tile' => max(0, (int) $request->input('tile', 400)),
                    'large_model' => $request->boolean('large_model'),
                    'timeout_seconds' => max(30, min(3600, (int) $request->input('timeout_seconds', 600))),
                    'output_format' => in_array($request->input('output_format'), ['jpg', 'png', 'webp'], true)
                        ? (string) $request->input('output_format')
                        : 'png',
                ],
                [
                    'api_key' => (string) $request->input('api_key', ''),
                ],
            ],
            default => [[], []],
        };

        $this->enhance->saveProvider($provider, $enabled, $settings, $secrets);

        return $this->redirectWithMessage(
            self::SETTINGS_PATH.'#enhance-'.$provider,
            __(':name の設定を保存しました', ['name' => $this->enhance->providerLabel($provider)])
        );
    }

    public function testProvider(string $provider): JsonResponse
    {
        $result = $this->enhance->testProvider($provider);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
