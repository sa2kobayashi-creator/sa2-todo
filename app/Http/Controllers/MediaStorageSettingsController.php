<?php

namespace App\Http\Controllers;

use App\Models\MediaStorageSetting;
use App\Services\MediaStorageConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaStorageSettingsController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(private MediaStorageConfigService $storageConfig) {}

    public function update(Request $request, string $provider)
    {
        if (! in_array($provider, $this->storageConfig->providers(), true)) {
            return $this->redirectWithMessage('/settings?section=storage', '不正なプロバイダです', 'error');
        }

        $enabled = $request->boolean('enabled');

        [$settings, $secrets] = match ($provider) {
            MediaStorageSetting::PROVIDER_R2 => [
                [
                    'bucket' => (string) $request->input('bucket', ''),
                    'endpoint' => (string) $request->input('endpoint', ''),
                    'url' => (string) $request->input('url', ''),
                    'region' => (string) $request->input('region', 'auto'),
                    'use_path_style_endpoint' => $request->boolean('use_path_style_endpoint'),
                ],
                [
                    'access_key_id' => (string) $request->input('access_key_id', ''),
                    'secret_access_key' => (string) $request->input('secret_access_key', ''),
                ],
            ],
            MediaStorageSetting::PROVIDER_CLOUDINARY => [
                [
                    'cloud_name' => (string) $request->input('cloud_name', ''),
                    'folder' => (string) $request->input('folder', 'sa2todo'),
                ],
                [
                    'api_key' => (string) $request->input('api_key', ''),
                    'api_secret' => (string) $request->input('api_secret', ''),
                ],
            ],
            MediaStorageSetting::PROVIDER_BACKBLAZE => [
                [
                    'bucket' => (string) $request->input('bucket', ''),
                    'endpoint' => (string) $request->input('endpoint', ''),
                    'url' => (string) $request->input('url', ''),
                    'region' => (string) $request->input('region', 'us-west-004'),
                    'use_path_style_endpoint' => $request->boolean('use_path_style_endpoint', true),
                ],
                [
                    'key_id' => (string) $request->input('key_id', ''),
                    'application_key' => (string) $request->input('application_key', ''),
                ],
            ],
            MediaStorageSetting::PROVIDER_PIPELINE => [
                [
                    'primary_disk' => in_array($request->input('primary_disk'), ['public', 'r2'], true)
                        ? (string) $request->input('primary_disk')
                        : 'r2',
                    'use_cloudinary_display' => $request->boolean('use_cloudinary_display'),
                    'archive_to_backblaze' => $request->boolean('archive_to_backblaze'),
                    'archive_after_days' => max(0, (int) $request->input('archive_after_days', 365)),
                ],
                [],
            ],
            default => [[], []],
        };

        $this->storageConfig->save($provider, $enabled, $settings, $secrets);

        $labels = [
            'r2' => 'Cloudflare R2',
            'cloudinary' => 'Cloudinary',
            'backblaze' => 'Backblaze B2',
            'pipeline' => __('保存パイプライン'),
        ];

        return $this->redirectWithMessage(
            '/settings?section=storage#storage-'.$provider,
            __(':name の設定を保存しました', ['name' => $labels[$provider] ?? $provider])
        );
    }

    public function test(Request $request, string $provider): JsonResponse
    {
        if (! in_array($provider, $this->storageConfig->providers(), true)) {
            return response()->json(['ok' => false, 'message' => __('不正なプロバイダです')], 422);
        }

        // テスト前にフォーム値を一時保存したい場合はクライアントが先に save する想定
        $result = $this->storageConfig->test($provider);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
