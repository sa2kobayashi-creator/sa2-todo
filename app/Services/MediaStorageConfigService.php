<?php

namespace App\Services;

use App\Models\MediaStorageSetting;
use Illuminate\Support\Facades\Cache;
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
            MediaStorageSetting::PROVIDER_REALESRGAN,
            MediaStorageSetting::PROVIDER_SWINIR,
            MediaStorageSetting::PROVIDER_PIPELINE,
            MediaStorageSetting::PROVIDER_ENHANCE,
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

        // フォーム保存でメータ値を消さない
        foreach ($row->settingsArray() as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'meter_') && ! array_key_exists($key, $cleanSettings)) {
                $cleanSettings[$key] = $value;
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
        return app(EnhanceConfigService::class)->isReady(EnhanceConfigService::PROVIDER_STABILITY);
    }

    public function realesrganEnabled(): bool
    {
        return app(EnhanceConfigService::class)->isReady(EnhanceConfigService::PROVIDER_REALESRGAN);
    }

    public function swinirEnabled(): bool
    {
        return app(EnhanceConfigService::class)->isReady(EnhanceConfigService::PROVIDER_SWINIR);
    }

    public function enhanceReady(): bool
    {
        return app(EnhanceConfigService::class)->isReady();
    }

    public function pipelineArchivesToBackblaze(): bool
    {
        $pipeline = $this->get(MediaStorageSetting::PROVIDER_PIPELINE);
        if (! $pipeline->enabled) {
            return false;
        }

        // 3つの運用モードはいずれも B2 連携を前提にする
        return in_array($this->capacityMode(), $this->capacityModes(), true);
    }

    public const CAPACITY_MODE_R2_CAP = 'r2_cap';

    public const CAPACITY_MODE_AGE_ARCHIVE = 'age_archive';

    public const CAPACITY_MODE_OVERFLOW = 'overflow_priority';

    /** @return list<string> */
    public function capacityModes(): array
    {
        return [
            self::CAPACITY_MODE_R2_CAP,
            self::CAPACITY_MODE_AGE_ARCHIVE,
            self::CAPACITY_MODE_OVERFLOW,
        ];
    }

    public function capacityMode(): string
    {
        $pipeline = $this->get(MediaStorageSetting::PROVIDER_PIPELINE);
        $mode = (string) $pipeline->setting('capacity_mode', '');

        // 旧設定からの移行: capacity_mode 未設定なら現行（日数アーカイブ）相当
        if ($mode === '' || ! in_array($mode, $this->capacityModes(), true)) {
            if ((bool) $pipeline->setting('archive_to_backblaze', false)) {
                return self::CAPACITY_MODE_AGE_ARCHIVE;
            }

            return self::CAPACITY_MODE_AGE_ARCHIVE;
        }

        return $mode;
    }

    public function overflowDisk(): string
    {
        $disk = (string) $this->get(MediaStorageSetting::PROVIDER_PIPELINE)
            ->setting('overflow_disk', 'public');

        return in_array($disk, ['public', 'r2', 'backblaze'], true) ? $disk : 'public';
    }

    public function overflowPricePerGbMonthUsd(): float
    {
        return max(0, (float) $this->get(MediaStorageSetting::PROVIDER_PIPELINE)
            ->setting('overflow_price_per_gb_month_usd', 0.015));
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

    /** 当月の B2 転送・操作メータを加算（見込課金用） */
    public function recordB2Usage(int $egressBytes = 0, int $classBOps = 0, int $classAOps = 0): void
    {
        if ($egressBytes <= 0 && $classBOps <= 0 && $classAOps <= 0) {
            return;
        }

        $ym = now()->format('Y-m');
        $ttl = now()->copy()->endOfMonth()->addDays(14);

        foreach ([
            "b2_meter:{$ym}:egress_bytes" => max(0, $egressBytes),
            "b2_meter:{$ym}:class_b" => max(0, $classBOps),
            "b2_meter:{$ym}:class_a" => max(0, $classAOps),
        ] as $key => $delta) {
            if ($delta <= 0) {
                continue;
            }
            if (! Cache::has($key)) {
                Cache::put($key, 0, $ttl);
            }
            Cache::increment($key, $delta);
        }
    }

    /**
     * @return array{
     *   month: string,
     *   egress_bytes: int,
     *   class_a: int,
     *   class_b: int,
     *   storage_usd: float,
     *   egress_usd: float,
     *   ops_usd: float,
     *   total_usd: float,
     *   free_egress_bytes: int
     * }
     */
    public function estimateB2BillUsd(int $storedBytes, float $storageOverageUsd): array
    {
        $ym = now()->format('Y-m');
        $cacheEgress = (int) Cache::get("b2_meter:{$ym}:egress_bytes", 0);
        $cacheClassA = (int) Cache::get("b2_meter:{$ym}:class_a", 0);
        $cacheClassB = (int) Cache::get("b2_meter:{$ym}:class_b", 0);

        $row = $this->get(MediaStorageSetting::PROVIDER_BACKBLAZE);
        $settings = $row->settingsArray();
        $dbMonth = (string) ($settings['meter_month'] ?? '');
        $dbEgress = $dbMonth === $ym ? (int) ($settings['meter_egress_bytes'] ?? 0) : 0;
        $dbClassA = $dbMonth === $ym ? (int) ($settings['meter_class_a'] ?? 0) : 0;
        $dbClassB = $dbMonth === $ym ? (int) ($settings['meter_class_b'] ?? 0) : 0;

        $egressBytes = max($cacheEgress, $dbEgress);
        $classA = max($cacheClassA, $dbClassA);
        $classB = max($cacheClassB, $dbClassB);

        // 使用状況表示時にスナップショットを残し、キャッシュ消失後も見込を維持
        if ($egressBytes > $dbEgress || $classA > $dbClassA || $classB > $dbClassB || $dbMonth !== $ym) {
            try {
                $settings['meter_month'] = $ym;
                $settings['meter_egress_bytes'] = $egressBytes;
                $settings['meter_class_a'] = $classA;
                $settings['meter_class_b'] = $classB;
                $row->settings = $settings;
                $row->save();
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $multiplier = max(0, (float) config('photos.b2_free_egress_storage_multiplier', 3));
        $freeEgressBytes = (int) floor(max(0, $storedBytes) * $multiplier);
        $billableEgress = max(0, $egressBytes - $freeEgressBytes);
        $egressPrice = max(0, (float) config('photos.b2_egress_price_per_gb_usd', 0.01));
        $egressUsd = round(($billableEgress / (1024 * 1024 * 1024)) * $egressPrice, 4);

        $classAPrice = max(0, (float) config('photos.b2_class_a_price_per_10k_usd', 0));
        $classBPrice = max(0, (float) config('photos.b2_class_b_price_per_10k_usd', 0));
        $classBFreePerDay = max(0, (int) config('photos.b2_class_b_free_per_day', 2500));
        $daysInMonth = max(1, (int) now()->daysInMonth);
        $classBFreeMonth = $classBPrice > 0 ? $classBFreePerDay * $daysInMonth : PHP_INT_MAX;
        $billableClassB = max(0, $classB - $classBFreeMonth);

        $opsUsd = round(
            ($classA / 10000) * $classAPrice
            + ($billableClassB / 10000) * $classBPrice,
            4
        );

        $storageUsd = max(0, $storageOverageUsd);

        return [
            'month' => $ym,
            'egress_bytes' => $egressBytes,
            'class_a' => $classA,
            'class_b' => $classB,
            'storage_usd' => $storageUsd,
            'egress_usd' => $egressUsd,
            'ops_usd' => $opsUsd,
            'total_usd' => round($storageUsd + $egressUsd + $opsUsd, 4),
            'free_egress_bytes' => $freeEgressBytes,
        ];
    }
}
