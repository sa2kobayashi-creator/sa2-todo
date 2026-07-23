<?php

namespace App\Services;

use App\Models\MediaStorageSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaStorageConfigService
{
    /** @return list<string> */
    public function providers(): array
    {
        return [
            MediaStorageSetting::PROVIDER_R2,
            MediaStorageSetting::PROVIDER_CLOUDINARY,
            MediaStorageSetting::PROVIDER_BACKBLAZE,
            MediaStorageSetting::PROVIDER_STABILITY,
            MediaStorageSetting::PROVIDER_PIPELINE,
        ];
    }

    public function get(string $provider): MediaStorageSetting
    {
        return MediaStorageSetting::forProvider($provider);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $secrets
     */
    public function save(string $provider, bool $enabled, array $settings, array $secrets): MediaStorageSetting
    {
        $row = $this->get($provider);
        $mergedSecrets = $row->secretsArray();
        foreach ($secrets as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            $trimmed = is_string($value) ? trim($value) : '';
            // 空またはマスク表示のままなら既存シークレットを維持
            if ($trimmed === '' || $trimmed === '••••••••' || str_starts_with($trimmed, '••••')) {
                continue;
            }
            $mergedSecrets[$key] = $trimmed;
        }

        $cleanSettings = [];
        foreach ($settings as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $cleanSettings[$key] = $value;
            } elseif (is_string($value)) {
                $cleanSettings[$key] = trim($value);
            }
        }

        $row->fill([
            'enabled' => $enabled,
            'settings' => $cleanSettings,
            'secrets' => $mergedSecrets,
        ]);
        $row->save();

        $this->applyRuntimeDisks();

        return $row->fresh() ?? $row;
    }

    public function applyRuntimeDisks(): void
    {
        try {
            $r2 = $this->get(MediaStorageSetting::PROVIDER_R2);
            if ($r2->enabled) {
                $config = $this->r2DiskConfig($r2);
                if ($config !== null) {
                    config(['filesystems.disks.r2' => array_merge(config('filesystems.disks.r2', []), $config)]);
                }
            }

            $b2 = $this->get(MediaStorageSetting::PROVIDER_BACKBLAZE);
            if ($b2->enabled) {
                $config = $this->backblazeDiskConfig($b2);
                if ($config !== null) {
                    config(['filesystems.disks.backblaze' => $config]);
                }
            }

            $pipeline = $this->get(MediaStorageSetting::PROVIDER_PIPELINE);
            if ($pipeline->enabled) {
                $disk = (string) $pipeline->setting('primary_disk', config('photos.disk', 'public'));
                if (in_array($disk, ['public', 'r2'], true)) {
                    config(['photos.disk' => $disk]);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return array{ok: bool, message: string} */
    public function test(string $provider): array
    {
        $row = $this->get($provider);

        try {
            $result = match ($provider) {
                MediaStorageSetting::PROVIDER_R2 => $this->testR2($row),
                MediaStorageSetting::PROVIDER_CLOUDINARY => $this->testCloudinary($row),
                MediaStorageSetting::PROVIDER_BACKBLAZE => $this->testBackblaze($row),
                MediaStorageSetting::PROVIDER_STABILITY => app(StabilityAiService::class)->testConnection(),
                MediaStorageSetting::PROVIDER_PIPELINE => ['ok' => true, 'message' => __('パイプライン設定を保存済みです')],
                default => ['ok' => false, 'message' => __('未知のプロバイダです')],
            };
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'message' => mb_substr($e->getMessage(), 0, 400)];
        }

        $row->last_tested_at = now();
        $row->last_test_status = $result['ok'] ? 'ok' : 'fail';
        $row->last_test_message = mb_substr($result['message'], 0, 500);
        $row->save();

        return $result;
    }

    public function cloudinaryEnabled(): bool
    {
        $row = $this->get(MediaStorageSetting::PROVIDER_CLOUDINARY);
        if (! $row->enabled) {
            return false;
        }

        return $row->setting('cloud_name')
            && $row->hasSecret('api_key')
            && $row->hasSecret('api_secret');
    }

    public function backblazeEnabled(): bool
    {
        $row = $this->get(MediaStorageSetting::PROVIDER_BACKBLAZE);

        return $row->enabled && $this->backblazeDiskConfig($row) !== null;
    }

    public function pipelineUsesCloudinaryDisplay(): bool
    {
        $pipeline = $this->get(MediaStorageSetting::PROVIDER_PIPELINE);

        // 既定は false（Cloudinary は編集専用。表示同期は明示オプトイン）
        return $pipeline->enabled && (bool) $pipeline->setting('use_cloudinary_display', false);
    }

    public function cloudinaryEditorEnabled(): bool
    {
        // 編集専用: Cloudinary 接続ができていれば Media Editor を使える
        return $this->cloudinaryEnabled();
    }

    public function stabilityEnabled(): bool
    {
        $row = $this->get(MediaStorageSetting::PROVIDER_STABILITY);

        return $row->enabled && $row->hasSecret('api_key');
    }

    public function pipelineArchivesToBackblaze(): bool
    {
        $pipeline = $this->get(MediaStorageSetting::PROVIDER_PIPELINE);

        return $pipeline->enabled && (bool) $pipeline->setting('archive_to_backblaze', false);
    }

    public function archiveAfterDays(): int
    {
        return max(0, (int) $this->get(MediaStorageSetting::PROVIDER_PIPELINE)->setting('archive_after_days', 365));
    }

    /** @return array<string, mixed>|null */
    private function r2DiskConfig(MediaStorageSetting $row): ?array
    {
        $key = (string) $row->secret('access_key_id', '');
        $secret = (string) $row->secret('secret_access_key', '');
        $bucket = (string) $row->setting('bucket', '');
        $endpoint = (string) $row->setting('endpoint', '');

        if ($key === '' || $secret === '' || $bucket === '' || $endpoint === '') {
            // DB 未設定なら .env の既存 r2 を使う
            if (config('filesystems.disks.r2.key') && config('filesystems.disks.r2.secret')) {
                return null;
            }

            return null;
        }

        return [
            'driver' => 's3',
            'key' => $key,
            'secret' => $secret,
            'region' => (string) $row->setting('region', 'auto'),
            'bucket' => $bucket,
            'url' => (string) $row->setting('url', ''),
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => (bool) $row->setting('use_path_style_endpoint', true),
            'throw' => false,
            'report' => false,
            'visibility' => 'public',
            'http' => [
                'timeout' => 600,
                'connect_timeout' => 30,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function backblazeDiskConfig(MediaStorageSetting $row): ?array
    {
        $key = (string) $row->secret('key_id', '');
        $secret = (string) $row->secret('application_key', '');
        $bucket = (string) $row->setting('bucket', '');
        $endpoint = (string) $row->setting('endpoint', '');

        if ($key === '' || $secret === '' || $bucket === '' || $endpoint === '') {
            return null;
        }

        return [
            'driver' => 's3',
            'key' => $key,
            'secret' => $secret,
            'region' => (string) $row->setting('region', 'us-west-004'),
            'bucket' => $bucket,
            'url' => (string) $row->setting('url', ''),
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => (bool) $row->setting('use_path_style_endpoint', true),
            'throw' => false,
            'report' => false,
            'visibility' => 'private',
            'http' => [
                'timeout' => 600,
                'connect_timeout' => 30,
            ],
        ];
    }

    /** @return array{ok: bool, message: string} */
    private function testR2(MediaStorageSetting $row): array
    {
        $this->applyRuntimeDisks();
        $config = $this->r2DiskConfig($row);
        if ($config !== null) {
            config(['filesystems.disks.r2' => array_merge(config('filesystems.disks.r2', []), $config)]);
        }

        if (! config('filesystems.disks.r2.key') || ! config('filesystems.disks.r2.bucket')) {
            return ['ok' => false, 'message' => __('R2 の Key / Bucket / Endpoint を入力してください')];
        }

        return $this->testS3Disk('r2', 'R2');
    }

    /** @return array{ok: bool, message: string} */
    private function testBackblaze(MediaStorageSetting $row): array
    {
        $config = $this->backblazeDiskConfig($row);
        if ($config === null) {
            return ['ok' => false, 'message' => __('Backblaze の Key / Bucket / Endpoint を入力してください')];
        }
        config(['filesystems.disks.backblaze' => $config]);

        return $this->testS3Disk('backblaze', 'Backblaze B2');
    }

    /** @return array{ok: bool, message: string} */
    private function testS3Disk(string $disk, string $label): array
    {
        $path = 'sa2-connection-tests/'.Str::uuid()->toString().'.txt';
        $payload = 'sa2-todo connection test '.now()->toIso8601String();

        Storage::disk($disk)->put($path, $payload, [
            'visibility' => 'private',
            'ContentType' => 'text/plain',
        ]);

        $exists = Storage::disk($disk)->exists($path);
        $body = $exists ? (string) Storage::disk($disk)->get($path) : '';
        Storage::disk($disk)->delete($path);

        if (! $exists || $body !== $payload) {
            return ['ok' => false, 'message' => __(':label への書き込み／読み取りに失敗しました', ['label' => $label])];
        }

        return ['ok' => true, 'message' => __(':label への接続に成功しました', ['label' => $label])];
    }

    /** @return array{ok: bool, message: string} */
    private function testCloudinary(MediaStorageSetting $row): array
    {
        $cloud = (string) $row->setting('cloud_name', '');
        $apiKey = (string) $row->secret('api_key', '');
        $apiSecret = (string) $row->secret('api_secret', '');

        if ($cloud === '' || $apiKey === '' || $apiSecret === '') {
            return ['ok' => false, 'message' => __('Cloudinary の Cloud name / API Key / API Secret を入力してください')];
        }

        $response = Http::withBasicAuth($apiKey, $apiSecret)
            ->timeout(20)
            ->get("https://api.cloudinary.com/v1_1/{$cloud}/resources/image", [
                'max_results' => 1,
            ]);

        if ($response->successful()) {
            return ['ok' => true, 'message' => __('Cloudinary への接続に成功しました')];
        }

        return [
            'ok' => false,
            'message' => __('Cloudinary 接続エラー: :detail', [
                'detail' => mb_substr($response->body() ?: ('HTTP '.$response->status()), 0, 300),
            ]),
        ];
    }

    /** @return array<string, mixed> */
    public function formState(string $provider): array
    {
        $row = $this->get($provider);
        $settings = $row->settingsArray();
        $envFallback = [];

        if ($provider === MediaStorageSetting::PROVIDER_R2) {
            $envFallback = [
                'access_key_id' => (string) config('filesystems.disks.r2.key', ''),
                'secret_access_key' => (string) config('filesystems.disks.r2.secret', ''),
                'bucket' => (string) ($settings['bucket'] ?? config('filesystems.disks.r2.bucket', '')),
                'endpoint' => (string) ($settings['endpoint'] ?? config('filesystems.disks.r2.endpoint', '')),
                'url' => (string) ($settings['url'] ?? config('filesystems.disks.r2.url', '')),
                'region' => (string) ($settings['region'] ?? config('filesystems.disks.r2.region', 'auto')),
                'use_path_style_endpoint' => (bool) ($settings['use_path_style_endpoint'] ?? config('filesystems.disks.r2.use_path_style_endpoint', true)),
            ];
        }

        return [
            'provider' => $provider,
            'enabled' => (bool) $row->enabled,
            'settings' => $settings,
            'envFallback' => $envFallback,
            'hasSecrets' => [
                'access_key_id' => $row->hasSecret('access_key_id'),
                'secret_access_key' => $row->hasSecret('secret_access_key'),
                'api_key' => $row->hasSecret('api_key'),
                'api_secret' => $row->hasSecret('api_secret'),
                'key_id' => $row->hasSecret('key_id'),
                'application_key' => $row->hasSecret('application_key'),
            ],
            'last_tested_at' => $row->last_tested_at?->format('Y-m-d H:i'),
            'last_test_status' => $row->last_test_status,
            'last_test_message' => $row->last_test_message,
        ];
    }
}
