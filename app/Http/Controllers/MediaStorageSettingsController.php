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
            return $this->redirectWithMessage('/settings?section=storage', __('不正なプロバイダです'), 'error');
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
                    'allow_paid_overage' => $request->boolean('allow_paid_overage'),
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
                    'allow_paid_overage' => $request->boolean('allow_paid_overage'),
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
                    'allow_paid_overage' => $request->boolean('allow_paid_overage'),
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
                    'allow_paid_overage' => $request->boolean('allow_paid_overage'),
                    'capacity_mode' => in_array($request->input('capacity_mode'), [
                        MediaStorageConfigService::CAPACITY_MODE_R2_CAP,
                        MediaStorageConfigService::CAPACITY_MODE_AGE_ARCHIVE,
                        MediaStorageConfigService::CAPACITY_MODE_OVERFLOW,
                    ], true)
                        ? (string) $request->input('capacity_mode')
                        : MediaStorageConfigService::CAPACITY_MODE_AGE_ARCHIVE,
                    'archive_to_backblaze' => true,
                    'archive_after_days' => max(0, (int) $request->input('archive_after_days', 365)),
                    'overflow_disk' => in_array($request->input('overflow_disk'), ['public', 'r2', 'backblaze'], true)
                        ? (string) $request->input('overflow_disk')
                        : 'public',
                    'overflow_price_per_gb_month_usd' => max(0, (float) $request->input('overflow_price_per_gb_month_usd', 0.015)),
                ],
                [],
            ],
            default => [[], []],
        };

        try {
            $this->storageConfig->save($provider, $enabled, $settings, $secrets);
        } catch (\Throwable $e) {
            report($e);

            return $this->redirectWithMessage(
                '/settings?section=storage#storage-'.$provider,
                __('設定の保存に失敗しました。APP_KEY を確認し、秘密鍵を再入力してください。'),
                'error'
            );
        }

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
