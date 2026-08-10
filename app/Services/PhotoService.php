<?php

namespace App\Services;

use App\Enums\AlbumVisibility;
use App\Jobs\SyncPhotoToCloudinary;
use App\Models\Photo;
use App\Models\PhotoAlbum;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class PhotoService
{
    public const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/heic',
        'image/heif',
    ];

    public const ALLOWED_VIDEO_MIMES = [
        'video/mp4',
        'video/quicktime',
        'video/x-msvideo',
        'video/avi',
        'video/msvideo',
    ];

    /** 端末によっては MIME が application/octet-stream で届くので拡張子でも受ける */
    public const ALLOWED_VIDEO_EXTENSIONS = [
        'mp4',
        'mov',
        'avi',
    ];

    /** ブラウザが再生できない形式。保存はするがダウンロードして見てもらう */
    public const UNPLAYABLE_VIDEO_EXTENSIONS = [
        'avi',
    ];

    public const ALLOWED_IMAGE_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'webp',
        'gif',
        'heic',
        'heif',
    ];

    /** @var array<int, int> */
    private array $usedBytesApproxCache = [];

    /** @var bool|null */
    private ?bool $paidOverageAllowedCache = null;

    public function __construct(
        private GroupService $groups,
        private FfmpegService $ffmpeg,
        private CloudinaryMediaService $cloudinary,
        private MediaStorageConfigService $mediaConfig,
        private StabilityAiService $stability,
    ) {}

    public function maxUploadBytes(): int
    {
        // 0 = アプリ側の画像サイズ上限なし（PHP の upload_max_filesize は別途あり）
        return max(0, (int) config('photos.max_upload_bytes', 0));
    }

    public function maxVideoUploadBytes(): int
    {
        return max(1, (int) config('photos.max_video_upload_bytes', 1024 * 1024 * 1024));
    }

    public function maxVideoUploadLabel(): string
    {
        return $this->formatBytes($this->maxVideoUploadBytes());
    }

    public function isVideoMime(?string $mime, ?string $extension = null): bool
    {
        $mime = strtolower((string) $mime);
        $extension = strtolower((string) $extension);
        if (in_array($extension, self::ALLOWED_VIDEO_EXTENSIONS, true)) {
            return true;
        }

        return in_array($mime, self::ALLOWED_VIDEO_MIMES, true)
            || str_starts_with($mime, 'video/mp4')
            || str_starts_with($mime, 'video/quicktime')
            || str_starts_with($mime, 'video/x-msvideo')
            || str_starts_with($mime, 'video/avi')
            || str_starts_with($mime, 'video/msvideo')
            || $mime === 'application/mp4'
            || $mime === 'application/quicktime';
    }

    public function isVideoUpload(UploadedFile $file): bool
    {
        return $this->isVideoMime($file->getMimeType(), $file->getClientOriginalExtension());
    }

    /**
     * 端末によっては MIME が octet-stream で保存されているので、拡張子でも数える。
     * isVideoMime() の判定を SQL に写したもの。
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Photo>  $query
     */
    private function constrainQueryToVideos($query): void
    {
        $query->where(function ($q) {
            foreach (self::ALLOWED_VIDEO_MIMES as $mime) {
                $q->orWhere('mime', 'like', $mime.'%');
            }
            $q->orWhere('mime', 'application/mp4')
                ->orWhere('mime', 'application/quicktime');
            foreach (self::ALLOWED_VIDEO_EXTENSIONS as $ext) {
                $q->orWhere('path', 'like', '%.'.$ext)
                    ->orWhere('original_name', 'like', '%.'.$ext);
            }
        });
    }

    public function countVideosForUser(int $userId): int
    {
        $query = Photo::query()->where('user_id', $userId);
        $this->constrainQueryToVideos($query);

        return (int) $query->count();
    }

    private function videoCountForUser(int $userId): int
    {
        return $this->countVideosForUser($userId);
    }

    public function userQuotaBytes(): int
    {
        return max(1, (int) config('photos.user_quota_bytes', 10 * 1024 * 1024 * 1024));
    }

    /** ユーザーごとの製品無料枠（R2+B2 合算）。既定 20GB */
    public function userFreeQuotaBytes(): int
    {
        return max(1, (int) config('photos.user_free_quota_bytes', 20 * 1024 * 1024 * 1024));
    }

    public function overagePricePerGbMonthUsd(): float
    {
        return max(0, (float) config('photos.overage_price_per_gb_month_usd', 0.015));
    }

    public function b2QuotaBytes(): int
    {
        return max(1, (int) config('photos.b2_quota_bytes', 10 * 1024 * 1024 * 1024));
    }

    public function b2OveragePricePerGbMonthUsd(): float
    {
        return max(0, (float) config('photos.b2_overage_price_per_gb_month_usd', 0.006));
    }

    /**
     * 将来の有料超過プラン（Stripe 等）でアップロード継続を許可するか。
     * ストレージ設定の「有料枠を許可」、または PHOTO_PAID_OVERAGE_ENABLED + ユーザーフラグ。
     */
    public function userAllowsPaidOverageUploads(int $userId): bool
    {
        if ($this->paidOverageAllowedCache === null) {
            $this->paidOverageAllowedCache = $this->mediaConfig->allowsPaidOverageFromSettings();
        }
        if ($this->paidOverageAllowedCache) {
            return true;
        }

        if (! (bool) config('photos.paid_overage_enabled', false)) {
            return false;
        }

        $user = \App\Models\User::query()->find($userId);
        if (! $user) {
            return false;
        }

        // 将来: Stripe 購読状態や storage_plan カラムを参照
        return (bool) ($user->storage_overage_active ?? false);
    }

    public function uploadsBlockedForUser(int $userId, int $extraBytes = 0): bool
    {
        if (! (bool) config('photos.block_uploads_over_free_quota', true)) {
            return false;
        }
        if ($this->userAllowsPaidOverageUploads($userId)) {
            return false;
        }

        return ($this->userUsedBytesApprox($userId) + max(0, $extraBytes)) > $this->userFreeQuotaBytes();
    }

    public function assertWithinFreeQuotaOrPaid(int $userId, int $extraBytes = 0): void
    {
        if (! $this->uploadsBlockedForUser($userId, $extraBytes)) {
            return;
        }

        $free = $this->formatBytes($this->userFreeQuotaBytes());
        $used = $this->formatBytes($this->userUsedBytesApprox($userId));
        throw new \InvalidArgumentException(
            __('無料枠（:free）を超えているため追加できません。使用量: :used。設定 → ストレージで「有料枠（無料枠超過）を許可する」をオンにすると追加できます。', [
                'free' => $free,
                'used' => $used,
            ])
        );
    }

    public function userUsedBytesApprox(int $userId): int
    {
        if (array_key_exists($userId, $this->usedBytesApproxCache)) {
            return $this->usedBytesApproxCache[$userId];
        }

        $thumbExtra = (int) Photo::query()
            ->where('user_id', $userId)
            ->whereNotNull('thumb_path')
            ->count() * 80_000;

        $bytes = (int) Photo::query()->where('user_id', $userId)->sum('size_bytes');

        return $this->usedBytesApproxCache[$userId] = $bytes + $thumbExtra;
    }

    private function bumpUsedBytesApproxCache(int $userId, int $deltaBytes): void
    {
        if (! array_key_exists($userId, $this->usedBytesApproxCache)) {
            return;
        }
        $this->usedBytesApproxCache[$userId] = max(0, $this->usedBytesApproxCache[$userId] + $deltaBytes);
    }

    private function formatUsdPerGbMonth(float $price): string
    {
        $trimmed = rtrim(rtrim(number_format($price, 3, '.', ''), '0'), '.');

        return '$'.$trimmed.__('/GB/月');
    }

    private function formatUsdMonth(float $amount): string
    {
        if ($amount <= 0) {
            return '$0';
        }

        return '$'.rtrim(rtrim(number_format($amount, 4, '.', ''), '0'), '.');
    }

    private function estimateOverageUsd(int $usedBytes, int $quotaBytes, float $pricePerGbMonth): float
    {
        $overBytes = max(0, $usedBytes - $quotaBytes);
        if ($overBytes <= 0) {
            return 0.0;
        }

        return round(($overBytes / (1024 * 1024 * 1024)) * $pricePerGbMonth, 4);
    }

    public function storageStats(int $userId): array
    {
        return Cache::remember(
            'photos:storage_stats:'.$userId,
            now()->addSeconds(45),
            fn () => $this->computeStorageStats($userId)
        );
    }

    /** アップロード／削除直後に呼ぶ */
    public function forgetStorageStatsCache(int $userId): void
    {
        Cache::forget('photos:storage_stats:'.$userId);
        unset($this->usedBytesApproxCache[$userId]);
    }

    public function computeStorageStats(int $userId): array
    {
        $thumbExtra = (int) Photo::query()
            ->where('user_id', $userId)
            ->whereNotNull('thumb_path')
            ->count() * 80_000;

        $hotQuery = Photo::query()
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->whereNull('storage_tier')->orWhere('storage_tier', 'hot');
            });
        $coldQuery = Photo::query()
            ->where('user_id', $userId)
            ->where('storage_tier', 'cold');
        $overflowQuery = Photo::query()
            ->where('user_id', $userId)
            ->where('storage_tier', 'overflow');

        $hotUsed = (int) (clone $hotQuery)->sum('size_bytes');
        $coldUsed = (int) (clone $coldQuery)->sum('size_bytes');
        $overflowUsed = (int) (clone $overflowQuery)->sum('size_bytes');
        $hotCount = (int) (clone $hotQuery)->count();
        $coldCount = (int) (clone $coldQuery)->count();
        $overflowCount = (int) (clone $overflowQuery)->count();
        $hotUsedApprox = $hotUsed + $thumbExtra;
        $usedApprox = $hotUsedApprox + $coldUsed + $overflowUsed;
        $this->usedBytesApproxCache[$userId] = $usedApprox;
        $photoCount = $hotCount + $coldCount + $overflowCount;
        $videoCount = $this->videoCountForUser($userId);
        $imageCount = max(0, $photoCount - $videoCount);

        $quota = $this->userQuotaBytes();
        $b2Quota = $this->b2QuotaBytes();
        $disk = $this->diskName();
        $r2Price = $this->overagePricePerGbMonthUsd();
        $b2Price = $this->b2OveragePricePerGbMonthUsd();

        $pipeline = $this->mediaConfig->get(\App\Models\MediaStorageSetting::PROVIDER_PIPELINE);
        $pipelineEnabled = (bool) $pipeline->enabled;
        $archiveEnabled = $this->mediaConfig->pipelineArchivesToBackblaze();
        $cloudinaryEditor = $this->mediaConfig->cloudinaryEditorEnabled();
        $cloudinaryEnabled = $this->mediaConfig->cloudinaryEnabled();
        $stabilityEnabled = $this->mediaConfig->stabilityEnabled();
        $enhance = app(EnhanceConfigService::class);
        $activeEnhanceLabel = $enhance->providerLabel($enhance->activeProvider());
        $enhanceReady = $enhance->isReady();

        // 製品無料枠は常にユーザー合計 20GB（R2+B2 合算相当）。ホット/コールドの内訳は表示用。
        $combinedQuota = $this->userFreeQuotaBytes();
        $displayCapacity = max(1, (int) config('photos.storage_display_capacity_bytes', 1024 * 1024 * 1024 * 1024));
        $barUsed = $usedApprox;
        // メインバーは表示容量（既定 1TiB）基準。無料枠超過バッジは別判定。
        $percent = round(($barUsed / $displayCapacity) * 100, 1);
        $freeMarkPercent = round(($combinedQuota / $displayCapacity) * 100, 2);
        $capacityMode = $this->mediaConfig->capacityMode();

        // 見込課金: プロバイダごとの無料枠超過を表示（カード合計＝超過課金見込）。
        // 製品無料枠合計 20GB はアップロード可否判定用。カードは R2 10GB / B2 10GB の内訳単価で見込む。
        $r2OverageUsd = $this->estimateOverageUsd($hotUsedApprox, $quota, $r2Price);
        $b2StorageUsd = $this->estimateOverageUsd($coldUsed, $b2Quota, $b2Price);
        $overflowPrice = $this->mediaConfig->overflowPricePerGbMonthUsd();
        $overflowDisk = $this->mediaConfig->overflowDisk();
        $overflowUsd = 0.0;

        // モード3: 合計が無料枠合計を超えた分を「次の保存先」単価で見込む
        if ($capacityMode === MediaStorageConfigService::CAPACITY_MODE_OVERFLOW && $usedApprox > $combinedQuota) {
            $overflowUsd = $this->estimateOverageUsd($usedApprox, $combinedQuota, $overflowPrice);
            $r2OverageUsd = 0.0;
            $b2StorageUsd = 0.0;
        }

        $b2Bill = $this->mediaConfig->estimateB2BillUsd($coldUsed, $b2StorageUsd);
        $b2OverageUsd = $b2Bill['total_usd'];
        $b2EgressPrice = max(0, (float) config('photos.b2_egress_price_per_gb_usd', 0.01));
        $b2ClassBPrice = max(0, (float) config('photos.b2_class_b_price_per_10k_usd', 0));
        $b2PriceLabel = $this->formatUsdPerGbMonth($b2Price)
            .' · '.__('転送料').' $'.rtrim(rtrim(number_format($b2EgressPrice, 3, '.', ''), '0'), '.').__('/GB')
            .' · '.__('操作料').' $'.rtrim(rtrim(number_format($b2ClassBPrice, 4, '.', ''), '0'), '.').__('/1万回');
        $b2BillingNote = __(
            '保管・転送・操作を合算（当月実測）。転送は保管量の約:mult倍まで無料・超過 :egress。操作は現行プランでは無料（単価0）。',
            [
                'mult' => rtrim(rtrim(number_format((float) config('photos.b2_free_egress_storage_multiplier', 3), 1, '.', ''), '0'), '.'),
                'egress' => '$'.rtrim(rtrim(number_format($b2EgressPrice, 3, '.', ''), '0'), '.').__('/GB'),
            ]
        );
        $b2Breakdown = [
            ['label' => __('保管料'), 'amount' => $this->formatUsdMonth($b2Bill['storage_usd']).__('/月')],
            ['label' => __('転送料'), 'amount' => $this->formatUsdMonth($b2Bill['egress_usd']).__('/月')],
            ['label' => __('操作料'), 'amount' => $this->formatUsdMonth($b2Bill['ops_usd']).__('/月')],
        ];

        $cloudinaryResidual = Photo::query()
            ->where('user_id', $userId)
            ->whereNotNull('cloudinary_public_id')
            ->where('cloudinary_public_id', '!=', '')
            ->count();
        $cloudinaryFreeCredits = max(1, (int) config('photos.cloudinary_free_credits', 25));

        $stabilityEnhanceCount = (int) Photo::query()
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->where('edit_label', 'AI鮮明化')
                    ->orWhere('edit_label', 'AI enhanced');
            })
            ->count();
        $stabilityCredits = $stabilityEnabled ? $this->stability->creditBalance() : null;

        $primaryLabel = match ($disk) {
            'r2' => 'Cloudflare R2',
            'public' => __('サーバーローカル'),
            default => $disk,
        };
        $diskLabel = $pipelineEnabled
            ? __('パイプライン').'（'.$primaryLabel
                .($archiveEnabled ? ' + Backblaze B2' : '')
                .($cloudinaryEditor ? ' + Cloudinary'.__('編集') : '')
                .($enhanceReady ? ' + '.$activeEnhanceLabel : '')
                .'）'
            : $primaryLabel;

        $providers = [
            [
                'id' => 'r2',
                'name' => 'Cloudflare R2',
                'role' => __('常用原本'),
                'enabled' => $disk === 'r2' || $pipelineEnabled,
                'usedLabel' => $this->formatBytes($hotUsedApprox),
                'quotaLabel' => $this->formatBytes($quota),
                'count' => $hotCount,
                'percent' => round(($hotUsedApprox / max(1, $quota)) * 100, 1),
                'overFreeTier' => $hotUsedApprox > $quota,
                'overagePriceLabel' => $this->formatUsdPerGbMonth($r2Price),
                'estimatedBillLabel' => $this->formatUsdMonth($r2OverageUsd).__('/月'),
                'billingNote' => __('無料枠超過分のみ従量課金。転送料は無料。'),
                'meter' => 'bytes',
            ],
            [
                'id' => 'backblaze',
                'name' => 'Backblaze B2',
                'role' => __('長期保存'),
                'enabled' => $archiveEnabled || $coldCount > 0,
                'usedLabel' => $this->formatBytes($coldUsed),
                'quotaLabel' => $this->formatBytes($b2Quota),
                'count' => $coldCount,
                'percent' => round(($coldUsed / max(1, $b2Quota)) * 100, 1),
                'overFreeTier' => $coldUsed > $b2Quota || $b2Bill['egress_usd'] > 0 || $b2Bill['ops_usd'] > 0,
                'overagePriceLabel' => $b2PriceLabel,
                'estimatedBillLabel' => $this->formatUsdMonth($b2OverageUsd).__('/月'),
                'billingNote' => $b2BillingNote,
                'billingBreakdown' => $b2Breakdown,
                'meter' => 'bytes',
            ],
            [
                'id' => 'cloudinary',
                'name' => 'Cloudinary',
                'role' => __('編集（一時のみ）'),
                'enabled' => $cloudinaryEnabled || $cloudinaryEditor,
                'usedLabel' => $cloudinaryResidual > 0
                    ? __('残存アセット :count 件', ['count' => $cloudinaryResidual])
                    : __('常設保管なし'),
                'quotaLabel' => $cloudinaryFreeCredits.__('クレジット/月'),
                'count' => $cloudinaryResidual,
                'percent' => 0,
                'overFreeTier' => false,
                'overagePriceLabel' => __('Free は超過課金なし（制限・アップグレード案内）'),
                'estimatedBillLabel' => '$0'.__('/月'),
                'billingNote' => __('1クレジット ≒ 1GB保管 または 1GB帯域 または 1,000変換。編集後は一時アセットを削除。'),
                'meter' => 'credits',
            ],
            [
                'id' => match ($enhance->activeProvider()) {
                    EnhanceConfigService::PROVIDER_REALESRGAN => 'realesrgan',
                    EnhanceConfigService::PROVIDER_SWINIR => 'swinir',
                    default => 'stability',
                },
                'name' => $activeEnhanceLabel,
                'role' => __('AI鮮明化'),
                'enabled' => $enhanceReady,
                'usedLabel' => match ($enhance->activeProvider()) {
                    EnhanceConfigService::PROVIDER_REALESRGAN => __('ローカル GPU・無料'),
                    EnhanceConfigService::PROVIDER_SWINIR => __('GPU VPS・無料（自前）'),
                    default => ($stabilityCredits !== null
                        ? __('残高 :credits クレジット', ['credits' => rtrim(rtrim(number_format($stabilityCredits, 4, '.', ''), '0'), '.')])
                        : ($stabilityEnabled ? __('残高を取得できませんでした') : __('—'))),
                },
                'quotaLabel' => match ($enhance->activeProvider()) {
                    EnhanceConfigService::PROVIDER_REALESRGAN => __('無料（自前 GPU）'),
                    EnhanceConfigService::PROVIDER_SWINIR => __('無料（自前 GPU VPS）'),
                    default => __('従量課金（クレジット）'),
                },
                'count' => $stabilityEnhanceCount,
                'percent' => 0,
                'overFreeTier' => false,
                'overagePriceLabel' => match ($enhance->activeProvider()) {
                    EnhanceConfigService::PROVIDER_REALESRGAN,
                    EnhanceConfigService::PROVIDER_SWINIR => __('電気代・VPS代のみ'),
                    default => __('リクエストごとにクレジット消費'),
                },
                'estimatedBillLabel' => match ($enhance->activeProvider()) {
                    EnhanceConfigService::PROVIDER_REALESRGAN,
                    EnhanceConfigService::PROVIDER_SWINIR => '$0'.__('/月').' + VPS',
                    default => __('従量（クレジット）'),
                },
                'billingNote' => match ($enhance->activeProvider()) {
                    EnhanceConfigService::PROVIDER_REALESRGAN => __('Real-ESRGAN（ncnn-vulkan）をローカル GPU で実行。結果は Cloudflare R2 へ保存します。'),
                    EnhanceConfigService::PROVIDER_SWINIR => __('SwinIR を GPU VPS 上で実行。結果は Cloudflare R2 へ保存します。'),
                    default => __('写真の AI 鮮明化（Upscale）に使用。残高は platform.stability.ai のダッシュボードでも確認できます。'),
                },
                'meter' => match ($enhance->activeProvider()) {
                    EnhanceConfigService::PROVIDER_REALESRGAN,
                    EnhanceConfigService::PROVIDER_SWINIR => 'local',
                    default => 'credits',
                },
                'countLabel' => __('鮮明化 :count 件', ['count' => $stabilityEnhanceCount]),
            ],
        ];

        // 「次の保存先」は超過時優先モード専用（R2/B2 無料枠を使い切った後の行き先）
        if ($capacityMode === MediaStorageConfigService::CAPACITY_MODE_OVERFLOW) {
            array_splice($providers, 2, 0, [[
                'id' => 'overflow',
                'name' => __('次の保存先'),
                'role' => match ($overflowDisk) {
                    'r2' => 'Cloudflare R2',
                    'backblaze' => 'Backblaze B2',
                    default => __('サーバーローカル'),
                },
                'enabled' => true,
                'usedLabel' => $this->formatBytes(max($overflowUsed, max(0, $usedApprox - $combinedQuota))),
                'quotaLabel' => __('無料枠合計後'),
                'count' => $overflowCount,
                'percent' => 0,
                'overFreeTier' => $overflowUsd > 0,
                'overagePriceLabel' => $this->formatUsdPerGbMonth($overflowPrice),
                'estimatedBillLabel' => $this->formatUsdMonth($overflowUsd).__('/月'),
                'billingNote' => __('R2/B2 の無料枠を超えた分の見込（設定した保存料）。'),
                'meter' => 'bytes',
            ]]);
        }

        return [
            'usedBytes' => $usedApprox,
            'quotaBytes' => $quota,
            'combinedQuotaBytes' => $combinedQuota,
            'displayCapacityBytes' => $displayCapacity,
            'percent' => $percent,
            'freeMarkPercent' => min(100, $freeMarkPercent),
            'photoCount' => $photoCount,
            'imageCount' => $imageCount,
            'videoCount' => $videoCount,
            'remainingBytes' => max(0, $combinedQuota - $barUsed),
            'formattedUsed' => $this->formatBytes($barUsed),
            'formattedQuota' => $this->formatBytes($quota),
            'formattedCombinedQuota' => $this->formatBytes($combinedQuota),
            'formattedDisplayCapacity' => $this->formatBytes($displayCapacity),
            'formattedTotalUsed' => $this->formatBytes($usedApprox),
            'disk' => $disk,
            'diskLabel' => $diskLabel,
            'overFreeTier' => $barUsed > $combinedQuota,
            'uploadsBlocked' => $this->uploadsBlockedForUser($userId),
            'paidOverageEnabled' => (bool) config('photos.paid_overage_enabled', false),
            'billingMode' => (bool) config('photos.paid_overage_enabled', false) ? 'paid' : 'estimate',
            'overagePriceLabel' => $this->formatUsdPerGbMonth($r2Price),
            'hotUsedBytes' => $hotUsedApprox,
            'coldUsedBytes' => $coldUsed,
            'hotCount' => $hotCount,
            'coldCount' => $coldCount,
            'formattedHotUsed' => $this->formatBytes($hotUsedApprox),
            'formattedColdUsed' => $this->formatBytes($coldUsed),
            'formattedB2Quota' => $this->formatBytes($b2Quota),
            'b2OveragePriceLabel' => $this->formatUsdPerGbMonth($b2Price),
            'hotOverFreeTier' => $hotUsedApprox > $quota,
            'coldOverFreeTier' => $coldUsed > $b2Quota,
            'r2EstimatedBillLabel' => $this->formatUsdMonth($r2OverageUsd).__('/月'),
            'b2EstimatedBillLabel' => $this->formatUsdMonth($b2OverageUsd).__('/月'),
            'estimatedTotalBillLabel' => $this->formatUsdMonth($r2OverageUsd + $b2OverageUsd + ($capacityMode === MediaStorageConfigService::CAPACITY_MODE_OVERFLOW ? $overflowUsd : 0)).__('/月'),
            'capacityMode' => $capacityMode,
            'archiveAfterDays' => $this->mediaConfig->archiveAfterDays(),
            'pipelineEnabled' => $pipelineEnabled,
            'archiveEnabled' => $archiveEnabled,
            'cloudinaryEditor' => $cloudinaryEditor,
            'stabilityEnabled' => $stabilityEnabled,
            'enhanceReady' => $enhanceReady,
            'enhanceProviderLabel' => $activeEnhanceLabel,
            'stabilityEnhanceCount' => $stabilityEnhanceCount,
            'primaryLabel' => $primaryLabel,
            'providers' => $providers,
        ];
    }

    public function diskName(): string
    {
        $disk = (string) config('photos.disk', 'public');

        return $disk !== '' ? $disk : 'public';
    }

    /**
     * 新しい写真を一覧先頭に出すための sort_order（既存 min - 10）。
     * 下限に近づいたら相対順を保ったまま振り直す。
     */
    private function allocateFrontPhotoSortOrder(int $userId): int
    {
        $min = Photo::query()->where('user_id', $userId)->min('sort_order');
        $next = $min === null ? 0 : ((int) $min - 10);

        // signed INT の下限に余裕を残す（約 2^31）
        if ($next < -2_000_000_000) {
            $this->renumberPhotoSortOrders($userId);
            $min = Photo::query()->where('user_id', $userId)->min('sort_order');
            $next = $min === null ? 0 : ((int) $min - 10);
        }

        return $next;
    }

    private function renumberPhotoSortOrders(int $userId): void
    {
        $ids = Photo::query()
            ->where('user_id', $userId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id');

        $order = 0;
        foreach ($ids as $id) {
            Photo::query()->where('id', $id)->update(['sort_order' => $order]);
            $order += 10;
        }
    }

    public function diskDriver(): string
    {
        return (string) config('filesystems.disks.'.$this->diskName().'.driver', 'local');
    }

    public function usesObjectStorage(): bool
    {
        return $this->diskDriver() === 's3';
    }

    /** @return list<array<string, mixed>> */
    public function listAlbums(int $userId, bool $includeHidden = false): array
    {
        $groupIds = $this->groups->approvedGroupIdsForUser($userId);

        $albums = PhotoAlbum::query()
            ->with(['group', 'user'])
            ->withCount('activePhotos')
            ->where(function ($q) use ($userId, $groupIds) {
                $q->where('user_id', $userId)
                    ->orWhere('visibility', AlbumVisibility::Public->value);
                if ($groupIds !== []) {
                    $q->orWhere(function ($groupQ) use ($groupIds) {
                        $groupQ->where('visibility', AlbumVisibility::Group->value)
                            ->whereIn('group_id', $groupIds);
                    });
                }
            })
            ->where(function ($q) use ($userId, $includeHidden) {
                // 隠しアルバムは本人かつ明示的に表示ONのときだけ一覧に出す
                $q->where('is_hidden', false);
                if ($includeHidden) {
                    $q->orWhere(function ($ownHidden) use ($userId) {
                        $ownHidden->where('user_id', $userId)->where('is_hidden', true);
                    });
                }
            })
            ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$userId])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $coverByAlbumId = $this->resolveAlbumCovers($albums);

        return $albums
            ->map(fn (PhotoAlbum $album) => $this->albumToArray(
                $album,
                $userId,
                $coverByAlbumId[(int) $album->id] ?? null
            ))
            ->all();
    }

    /**
     * アルバム表紙をまとめて解決（N+1 回避）。
     *
     * @param  \Illuminate\Support\Collection<int, PhotoAlbum>  $albums
     * @return array<int, Photo>
     */
    private function resolveAlbumCovers($albums): array
    {
        if ($albums->isEmpty()) {
            return [];
        }

        $coverIds = $albums->pluck('cover_photo_id')->filter()->unique()->values()->all();
        $coversById = $coverIds === []
            ? collect()
            : Photo::query()->whereIn('id', $coverIds)->get()->keyBy('id');

        $needFallbackIds = [];
        foreach ($albums as $album) {
            $coverId = $album->cover_photo_id ? (int) $album->cover_photo_id : null;
            if (! $coverId || ! $coversById->has($coverId)) {
                $needFallbackIds[] = (int) $album->id;
            }
        }

        $fallbackByAlbumId = [];
        if ($needFallbackIds !== []) {
            $latestIds = Photo::query()
                ->selectRaw('album_id, MAX(id) as max_id')
                ->whereIn('album_id', $needFallbackIds)
                ->groupBy('album_id')
                ->pluck('max_id', 'album_id');
            if ($latestIds->isNotEmpty()) {
                $fallbackPhotos = Photo::query()->whereIn('id', $latestIds->values()->all())->get()->keyBy('id');
                foreach ($latestIds as $albumId => $photoId) {
                    $photo = $fallbackPhotos->get($photoId);
                    if ($photo) {
                        $fallbackByAlbumId[(int) $albumId] = $photo;
                    }
                }
            }
        }

        $result = [];
        foreach ($albums as $album) {
            $albumId = (int) $album->id;
            $coverId = $album->cover_photo_id ? (int) $album->cover_photo_id : null;
            if ($coverId && $coversById->has($coverId)) {
                $result[$albumId] = $coversById->get($coverId);
            } elseif (isset($fallbackByAlbumId[$albumId])) {
                $result[$albumId] = $fallbackByAlbumId[$albumId];
            }
        }

        return $result;
    }

    public function canViewAlbum(int $userId, PhotoAlbum $album): bool
    {
        if ((int) $album->user_id === $userId) {
            return true;
        }
        // 隠しアルバムは所有者以外には見せない（公開範囲より優先）
        if ($album->is_hidden) {
            return false;
        }
        $visibility = $album->visibilityEnum();
        if ($visibility === AlbumVisibility::Public) {
            return true;
        }
        if ($visibility === AlbumVisibility::Group && $album->group_id) {
            return $this->groups->userBelongsToApprovedGroup($userId, (int) $album->group_id);
        }

        return false;
    }

    public function albumNeedsUnlock(PhotoAlbum $album, int $userId): bool
    {
        if (! $album->hasPassword()) {
            return false;
        }
        if (! $this->canViewAlbum($userId, $album)) {
            return false;
        }

        return ! $this->isAlbumUnlocked($userId, (int) $album->id);
    }

    public function isAlbumUnlocked(int $userId, int $albumId): bool
    {
        $key = 'photos_album_unlocks_'.$userId;
        $ids = session($key, []);

        return is_array($ids) && in_array($albumId, array_map('intval', $ids), true);
    }

    public function unlockAlbum(int $userId, int $albumId, string $password): bool
    {
        $album = PhotoAlbum::query()->find($albumId);
        if (! $album || ! $this->canViewAlbum($userId, $album) || ! $album->hasPassword()) {
            return false;
        }
        if (! \Illuminate\Support\Facades\Hash::check($password, (string) $album->password_hash)) {
            return false;
        }
        $key = 'photos_album_unlocks_'.$userId;
        $ids = session($key, []);
        if (! is_array($ids)) {
            $ids = [];
        }
        $ids[] = $albumId;
        session([$key => array_values(array_unique(array_map('intval', $ids)))]);

        return true;
    }

    public function canManageAlbum(int $userId, PhotoAlbum $album): bool
    {
        return (int) $album->user_id === $userId;
    }

    public function findViewableAlbum(int $userId, int $albumId): ?PhotoAlbum
    {
        $album = PhotoAlbum::query()->with(['group', 'user'])->find($albumId);
        if (! $album || ! $this->canViewAlbum($userId, $album)) {
            return null;
        }

        return $album;
    }

    /** @param 'active'|'archived' $library */
    private function applyLibraryScope($query, string $library)
    {
        if ($library === 'archived') {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }

        return $query;
    }

    /**
     * ルート表示の所属範囲。
     * - loose: アルバム未所属のみ（既定）
     * - library: 非隠しアルバム所属も含む（隠しアルバム内はルートに出さない）
     *
     * @param  'loose'|'library'  $scope
     */
    private function applyRootAlbumScope($query, ?int $albumId, string $scope)
    {
        if ($albumId !== null) {
            return $query;
        }
        if ($scope !== 'library') {
            $query->whereNull('album_id');

            return $query;
        }

        // アルバム含む: 未所属 + 非隠しアルバムのみ（隠しアルバムはアルバム画面からのみ）
        $query->where(function ($q) {
            $q->whereNull('album_id')
                ->orWhereHas('album', function ($albumQ) {
                    $albumQ->where('is_hidden', false);
                });
        });

        return $query;
    }

    /** 年フィルタ用。全件ロードせず DISTINCT で取得する。 @return list<int> */
    public function listPhotoYears(
        int $userId,
        ?int $albumId = null,
        string $library = 'active',
        string $scope = 'loose',
    ): array {
        $query = Photo::query();
        if ($albumId !== null) {
            $album = $this->findViewableAlbum($userId, $albumId);
            if (! $album) {
                return [];
            }
            $query->where('album_id', $albumId);
        } else {
            $query->where('user_id', $userId);
        }
        $this->applyLibraryScope($query, $library === 'archived' ? 'archived' : 'active');
        $this->applyRootAlbumScope($query, $albumId, $library === 'archived' ? 'library' : $scope);

        $driver = $query->getConnection()->getDriverName();
        $yearExpr = match ($driver) {
            'sqlite' => "CAST(strftime('%Y', taken_at) AS INTEGER)",
            'pgsql' => 'EXTRACT(YEAR FROM taken_at)::integer',
            default => 'YEAR(taken_at)',
        };

        return $query
            ->whereNotNull('taken_at')
            ->selectRaw('DISTINCT '.$yearExpr.' as y')
            ->orderByDesc('y')
            ->pluck('y')
            ->map(fn ($y) => (int) $y)
            ->filter(fn (int $y) => $y >= 1970 && $y <= 2100)
            ->values()
            ->all();
    }

    public function countPhotos(
        int $userId,
        ?int $albumId = null,
        string $library = 'active',
        string $scope = 'loose',
    ): int {
        $query = Photo::query();
        if ($albumId !== null) {
            $album = $this->findViewableAlbum($userId, $albumId);
            if (! $album) {
                return 0;
            }
            $query->where('album_id', $albumId);
        } else {
            $query->where('user_id', $userId);
        }
        $this->applyLibraryScope($query, $library === 'archived' ? 'archived' : 'active');
        $this->applyRootAlbumScope($query, $albumId, $library === 'archived' ? 'library' : $scope);

        return (int) $query->count();
    }

    /** @return list<array<string, mixed>> */
    public function listPhotos(
        int $userId,
        ?int $albumId = null,
        string $sort = 'taken_desc',
        ?int $year = null,
        ?int $limit = null,
        string $library = 'active',
        string $scope = 'loose',
    ): array {
        $query = Photo::query();
        if ($albumId !== null) {
            $album = $this->findViewableAlbum($userId, $albumId);
            if (! $album) {
                return [];
            }
            $query->where('album_id', $albumId);
        } else {
            $query->where('user_id', $userId);
        }
        $this->applyLibraryScope($query, $library === 'archived' ? 'archived' : 'active');
        $this->applyRootAlbumScope($query, $albumId, $library === 'archived' ? 'library' : $scope);

        if ($year !== null && $year >= 1970 && $year <= 2100) {
            $query->whereYear('taken_at', $year);
        }

        match ($sort) {
            'taken_asc' => $query->orderBy('taken_at')->orderBy('id'),
            'name_asc' => $query->orderBy('original_name')->orderByDesc('id'),
            'name_desc' => $query->orderByDesc('original_name')->orderByDesc('id'),
            'size_desc' => $query->orderByDesc('size_bytes')->orderByDesc('id'),
            'size_asc' => $query->orderBy('size_bytes')->orderByDesc('id'),
            default => $query->orderByDesc('taken_at')->orderByDesc('id'),
        };

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query
            ->get()
            ->map(fn (Photo $photo) => $this->photoToArray($photo, $userId))
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $photos
     * @return list<int>
     */
    public function photoYearOptions(array $photos): array
    {
        $years = [];
        foreach ($photos as $photo) {
            $date = substr((string) ($photo['takenAt'] ?? ''), 0, 4);
            if (preg_match('/^\d{4}$/', $date)) {
                $years[(int) $date] = true;
            }
        }
        $list = array_keys($years);
        rsort($list);

        return $list;
    }

    /**
     * いま並んでいるグループに含まれる年。年ジャンプの行き先なので表示順のまま返す。
     *
     * @param  list<array{date?: string, label?: string, photos?: list<array<string, mixed>>}>  $groups
     * @return list<int>
     */
    public function groupYearOptions(array $groups): array
    {
        $years = [];
        foreach ($groups as $group) {
            $year = substr((string) ($group['date'] ?? ''), 0, 4);
            if (preg_match('/^\d{4}$/', $year)) {
                $years[(int) $year] = true;
            }
        }

        return array_keys($years);
    }

    /**
     * コピー元の撮影日時を優先: EXIF → クライアントヒント → サーバー時刻。
     */
    public function resolveTakenAtForUpload(UploadedFile $file, ?string $clientHint = null): \Carbon\Carbon
    {
        $fromExif = $this->readTakenAtFromExif($file);
        if ($fromExif !== null) {
            return $fromExif;
        }

        $fromClient = $this->normalizeTakenAt($clientHint);
        if ($fromClient !== null) {
            return $fromClient;
        }

        return now();
    }

    private function readTakenAtFromExif(UploadedFile $file): ?\Carbon\Carbon
    {
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '' || ! function_exists('exif_read_data')) {
            return null;
        }

        $mime = strtolower((string) ($file->getMimeType() ?: ''));
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $looksJpeg = str_contains($mime, 'jpeg') || str_contains($mime, 'jpg') || in_array($ext, ['jpg', 'jpeg'], true);
        if (! $looksJpeg) {
            return null;
        }

        try {
            $exif = @exif_read_data($path, 'EXIF', true);
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($exif)) {
            return null;
        }

        $candidates = [
            $exif['EXIF']['DateTimeOriginal'] ?? null,
            $exif['EXIF']['DateTimeDigitized'] ?? null,
            $exif['IFD0']['DateTime'] ?? null,
            $exif['DateTimeOriginal'] ?? null,
            $exif['DateTime'] ?? null,
        ];

        $tz = config('app.timezone', 'Asia/Tokyo');
        foreach ($candidates as $raw) {
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }
            $normalized = str_replace('-', ':', trim($raw));
            // EXIF: "YYYY:MM:DD HH:MM:SS"
            if (preg_match('/^(\d{4}):(\d{2}):(\d{2})\s+(\d{2}):(\d{2}):(\d{2})$/', $normalized, $m)) {
                try {
                    return \Carbon\Carbon::create(
                        (int) $m[1],
                        (int) $m[2],
                        (int) $m[3],
                        (int) $m[4],
                        (int) $m[5],
                        (int) $m[6],
                        $tz
                    );
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * 動画のみを SQL で取得する（全写真を読んでフィルタしない）。
     *
     * @return list<array<string, mixed>>
     */
    public function listVideos(int $userId, ?int $limit = null): array
    {
        $query = Photo::query()
            ->where('user_id', $userId)
            ->orderByDesc('taken_at')
            ->orderByDesc('id');
        $this->constrainQueryToVideos($query);
        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query
            ->get()
            ->map(fn (Photo $photo) => $this->photoToArray($photo, $userId))
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $photos
     * @return list<array{date: string, label: string, photos: list<array<string, mixed>>}>
     */
    public function groupPhotosForDisplay(array $photos, string $sort = 'taken_desc'): array
    {
        if (in_array($sort, ['name_asc', 'name_desc', 'size_asc', 'size_desc'], true)) {
            return [[
                'date' => 'all',
                'label' => __('すべて'),
                'photos' => $photos,
            ]];
        }

        return $this->groupPhotosByDate($photos);
    }

    /**
     * @param  list<array<string, mixed>>  $photos
     * @return list<array{date: string, label: string, photos: list<array<string, mixed>>}>
     */
    public function groupPhotosByDate(array $photos): array
    {
        $tz = config('app.timezone', 'Asia/Tokyo');
        $today = now($tz)->format('Y-m-d');
        $yesterday = now($tz)->subDay()->format('Y-m-d');
        $groups = [];

        foreach ($photos as $photo) {
            $date = substr((string) ($photo['takenAt'] ?? ''), 0, 10);
            if ($date === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $date = substr((string) ($photo['createdAt'] ?? ''), 0, 10) ?: 'unknown';
            }
            if (! isset($groups[$date])) {
                $groups[$date] = [
                    'date' => $date,
                    'label' => $this->formatDateGroupLabel($date, $today, $yesterday),
                    'photos' => [],
                ];
            }
            $groups[$date]['photos'][] = $photo;
        }

        return array_values($groups);
    }

    public function createAlbum(
        int $userId,
        string $name,
        ?string $description = null,
        string $visibility = 'private',
        mixed $groupId = null,
        ?string $password = null,
        bool $isHidden = false,
    ): array {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('アルバム名を入力してください');
        }

        [$visibilityEnum, $resolvedGroupId] = $this->normalizeAlbumVisibility($userId, $visibility, $groupId);

        $passwordHash = null;
        if ($password !== null && trim($password) !== '') {
            if (mb_strlen($password) < 4) {
                throw new \InvalidArgumentException(__('パスワードは4文字以上にしてください。'));
            }
            $passwordHash = \Illuminate\Support\Facades\Hash::make($password);
        }

        // 隠しアルバムは共有不可（本人専用）
        if ($isHidden) {
            $visibilityEnum = AlbumVisibility::Private;
            $resolvedGroupId = null;
        }

        $max = (int) PhotoAlbum::query()->where('user_id', $userId)->max('sort_order');
        $album = PhotoAlbum::create([
            'user_id' => $userId,
            'name' => mb_substr($name, 0, 120),
            'description' => $description !== null ? mb_substr(trim($description), 0, 500) : null,
            'password_hash' => $passwordHash,
            'is_hidden' => $isHidden,
            'visibility' => $visibilityEnum,
            'group_id' => $resolvedGroupId,
            'sort_order' => $max + 10,
        ]);

        return $this->albumToArray($album->load(['group', 'user'])->loadCount('activePhotos'), $userId);
    }

    /**
     * フォルダ取り込み用のアルバムを作る。
     * 同じフォルダを二度取り込んでも前回分と混ざらないよう、名前が衝突したら連番を足す。
     */
    public function createAlbumForFolder(int $userId, string $folderName): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', str_replace(['/', '\\'], ' ', $folderName)) ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('フォルダ名が空です');
        }

        return $this->createAlbum($userId, $this->uniqueAlbumName($userId, mb_substr($name, 0, 120)));
    }

    private function uniqueAlbumName(int $userId, string $name): string
    {
        $taken = PhotoAlbum::query()
            ->where('user_id', $userId)
            ->pluck('name')
            ->map(fn ($existing) => mb_strtolower(trim((string) $existing)))
            ->all();

        if (! in_array(mb_strtolower($name), $taken, true)) {
            return $name;
        }

        // 120 文字上限があるので、連番の分だけ本体を削ってから足す
        for ($i = 2; $i < 1000; $i++) {
            $suffix = ' ('.$i.')';
            $candidate = mb_substr($name, 0, 120 - mb_strlen($suffix)).$suffix;
            if (! in_array(mb_strtolower($candidate), $taken, true)) {
                return $candidate;
            }
        }

        return mb_substr($name, 0, 108).' ('.now()->format('YmdHis').')';
    }

    public function updateAlbum(
        int $userId,
        int $albumId,
        string $name,
        ?string $description = null,
        ?string $visibility = null,
        mixed $groupId = null,
        ?string $password = null,
        bool $clearPassword = false,
        ?bool $isHidden = null,
        ?int $coverPhotoId = null,
    ): array {
        $album = PhotoAlbum::query()->where('user_id', $userId)->find($albumId);
        if (! $album) {
            throw new \InvalidArgumentException('アルバムが見つかりません');
        }

        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('アルバム名を入力してください');
        }

        $album->name = mb_substr($name, 0, 120);
        $album->description = $description !== null ? mb_substr(trim($description), 0, 500) : null;
        if ($album->description === '') {
            $album->description = null;
        }
        if ($isHidden !== null) {
            $album->is_hidden = $isHidden;
        }
        if ($album->is_hidden) {
            $album->visibility = AlbumVisibility::Private;
            $album->group_id = null;
        } elseif ($visibility !== null) {
            [$visibilityEnum, $resolvedGroupId] = $this->normalizeAlbumVisibility($userId, $visibility, $groupId);
            $album->visibility = $visibilityEnum;
            $album->group_id = $resolvedGroupId;
        }
        if ($clearPassword) {
            $album->password_hash = null;
        } elseif ($password !== null && trim($password) !== '') {
            if (mb_strlen($password) < 4) {
                throw new \InvalidArgumentException(__('パスワードは4文字以上にしてください。'));
            }
            $album->password_hash = \Illuminate\Support\Facades\Hash::make($password);
        }
        if ($coverPhotoId !== null) {
            $cover = Photo::query()
                ->where('user_id', $userId)
                ->where('id', $coverPhotoId)
                ->where('album_id', $albumId)
                ->whereNull('archived_at')
                ->first();
            if (! $cover) {
                throw new \InvalidArgumentException(__('このアルバム内の写真のみ表紙に設定できます'));
            }
            $album->cover_photo_id = $cover->id;
        }
        $album->save();

        return $this->albumToArray($album->load(['group', 'user'])->loadCount('activePhotos'), $userId);
    }

    /** @return array{0: \App\Enums\AlbumVisibility, 1: ?int} */
    private function normalizeAlbumVisibility(int $userId, string $visibility, mixed $groupId): array
    {
        $visibilityEnum = AlbumVisibility::tryFrom($visibility) ?? AlbumVisibility::Private;
        $resolvedGroupId = null;
        if ($visibilityEnum === AlbumVisibility::Group) {
            $resolvedGroupId = (int) $groupId;
            if ($resolvedGroupId <= 0 || ! $this->groups->userBelongsToApprovedGroup($userId, $resolvedGroupId)) {
                throw new \InvalidArgumentException(__('グループのみ公開には有効なグループが必要です。'));
            }
        }

        return [$visibilityEnum, $resolvedGroupId];
    }

    public function setAlbumCover(int $userId, int $albumId, int $photoId): array
    {
        $album = PhotoAlbum::query()->where('user_id', $userId)->find($albumId);
        if (! $album) {
            throw new \InvalidArgumentException('アルバムが見つかりません');
        }

        $photo = Photo::query()
            ->where('user_id', $userId)
            ->where('id', $photoId)
            ->where('album_id', $albumId)
            ->whereNull('archived_at')
            ->first();
        if (! $photo) {
            throw new \InvalidArgumentException('このアルバム内の写真のみ表紙に設定できます');
        }

        $album->cover_photo_id = $photo->id;
        $album->save();

        return $this->albumToArray($album->loadCount('activePhotos'), $userId);
    }

    /**
     * アルバム編集の表紙候補（通常表示の写真のみ）。現在の表紙を先頭に含める。
     *
     * @return list<array{id: int, thumbUrl: ?string, url: ?string, mediaKind: string}>
     */
    public function listAlbumCoverCandidates(int $userId, int $albumId, int $limit = 48): array
    {
        $album = PhotoAlbum::query()->where('user_id', $userId)->find($albumId);
        if (! $album) {
            return [];
        }

        $limit = max(1, min(96, $limit));
        $base = Photo::query()
            ->where('user_id', $userId)
            ->where('album_id', $albumId)
            ->whereNull('archived_at');

        $picked = collect();
        if ($album->cover_photo_id) {
            $cover = (clone $base)->where('id', (int) $album->cover_photo_id)->first();
            if ($cover) {
                $picked->push($cover);
            }
        }

        $restLimit = max(0, $limit - $picked->count());
        if ($restLimit > 0) {
            $excludeIds = $picked->pluck('id')->all();
            $rest = (clone $base)
                ->when($excludeIds !== [], fn ($q) => $q->whereNotIn('id', $excludeIds))
                ->orderByDesc('taken_at')
                ->orderByDesc('id')
                ->limit($restLimit)
                ->get();
            $picked = $picked->concat($rest);
        }

        return $picked
            ->map(function (Photo $photo) use ($userId): array {
                $arr = $this->photoToArray($photo, $userId);

                return [
                    'id' => (int) $photo->id,
                    'thumbUrl' => $arr['thumbUrl'] ?? null,
                    'url' => $arr['url'] ?? null,
                    'mediaKind' => $arr['mediaKind'] ?? 'image',
                    'takenDate' => $arr['takenDate'] ?? null,
                    'originalName' => $arr['originalName'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<UploadedFile>  $files
     * @param  array<int, string>  $takenAtByIndex
     * @param  array<int, string>  $contentHashByIndex  クライアント計算の sa2-photo-v1 / SHA-256（再計算を省略）
     * @return array{created: list<array<string, mixed>>, skipped: list<array{name: string, hash: string}>}
     */
    public function uploadPhotos(
        int $userId,
        array $files,
        ?int $albumId = null,
        array $videoThumbsByIndex = [],
        bool $allowDuplicates = false,
        array $takenAtByIndex = [],
        array $contentHashByIndex = []
    ): array {
        if ($albumId !== null) {
            $album = PhotoAlbum::query()->where('user_id', $userId)->find($albumId);
            if (! $album) {
                throw new \InvalidArgumentException('アルバムが見つかりません');
            }
        }

        $created = [];
        $skipped = [];
        $nextOrder = $this->allocateFrontPhotoSortOrder($userId);
        $archive = app(PhotoColdArchiveService::class);

        foreach ($files as $index => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            if (! $file->isValid()) {
                $this->throwUploadError($file);
                continue;
            }
            $this->assertValidUpload($file);

            $path = $file->getRealPath();
            $clientHash = $contentHashByIndex[$index] ?? null;
            if (is_string($clientHash) && preg_match('/^[a-f0-9]{64}$/i', trim($clientHash))) {
                $hash = strtolower(trim($clientHash));
            } else {
                $hash = is_string($path) && $path !== '' ? $this->computeContentHashFromPath($path) : null;
            }
            $originalName = mb_substr((string) $file->getClientOriginalName(), 0, 255);

            if ($hash && ! $allowDuplicates && $this->findOwnedByContentHash($userId, $hash)) {
                $skipped[] = ['name' => $originalName !== '' ? $originalName : 'file', 'hash' => $hash];
                continue;
            }

            $incomingBytes = max(0, (int) $file->getSize());
            $this->assertWithinFreeQuotaOrPaid($userId, $incomingBytes);
            // 同期アーカイブは最小限（既定1件）。大量の R2→B2 移動は cron に任せ、保存体感を落とさない
            $syncArchiveLimit = max(0, (int) config('photos.upload_sync_archive_limit', 1));
            if ($syncArchiveLimit > 0) {
                $archive->ensureHotWithinQuota($userId, $incomingBytes, $syncArchiveLimit);
            }
            $target = $this->resolveUploadTarget($userId, $incomingBytes);

            $dir = 'photos/'.$userId.'/'.now()->format('Y/m');
            $videoThumb = $videoThumbsByIndex[$index] ?? null;
            try {
                $stored = $this->isVideoMime($file->getMimeType(), $file->getClientOriginalExtension())
                    ? $this->storeVideo($file, $dir, $videoThumb instanceof UploadedFile ? $videoThumb : null, $target['disk'])
                    : $this->storeOptimizedImage($file, $dir, $target['disk']);
            } catch (\InvalidArgumentException $e) {
                throw $e;
            } catch (\Throwable $e) {
                report($e);
                throw new \InvalidArgumentException(
                    '保存に失敗しました（'.$target['disk'].'）: '.mb_substr($e->getMessage(), 0, 180)
                );
            }
            if ($stored === null) {
                throw new \InvalidArgumentException(
                    'ファイルを保存できませんでした。ストレージ設定（R2 / ディスク）と接続テストを確認してください。'
                );
            }

            if (($target['tier'] ?? '') === 'cold' && ($target['disk'] ?? '') === 'backblaze') {
                $this->mediaConfig->recordB2Usage(0, 0, 1);
            }

            try {
                $photo = Photo::create([
                    'user_id' => $userId,
                    'album_id' => $albumId,
                    'path' => $stored['path'],
                    'thumb_path' => $stored['thumbPath'],
                    'original_name' => $originalName,
                    'mime' => $stored['mime'],
                    'size_bytes' => $stored['sizeBytes'],
                    'content_hash' => $allowDuplicates ? null : $hash,
                    'width' => $stored['width'],
                    'height' => $stored['height'],
                    'taken_at' => $this->resolveTakenAtForUpload(
                        $file,
                        is_string($takenAtByIndex[$index] ?? null) ? $takenAtByIndex[$index] : null
                    ),
                    'sort_order' => $nextOrder,
                    'storage_tier' => $target['tier'],
                    'cold_disk' => in_array($target['tier'], ['cold', 'overflow'], true) ? $target['disk'] : null,
                    'cold_path' => in_array($target['tier'], ['cold', 'overflow'], true) ? $stored['path'] : null,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // 並行アップロード時の競合（原本とサムネが別ディスクの場合あり）
                $this->deleteStoragePaths(array_values(array_filter([
                    $stored['path'] ?? null,
                    $stored['thumbPath'] ?? null,
                ])), $target['disk']);
                if ($hash) {
                    $skipped[] = ['name' => $originalName !== '' ? $originalName : 'file', 'hash' => $hash];
                }
                continue;
            }

            $nextOrder -= 10;
            $this->bumpUsedBytesApproxCache($userId, (int) ($stored['sizeBytes'] ?? 0) + 80_000);
            $this->maybeSyncCloudinary($photo);
            $created[] = $this->photoToArray($photo, $userId);

            if ($albumId !== null) {
                $album = PhotoAlbum::query()->where('user_id', $userId)->find($albumId);
                if ($album && ! $album->cover_photo_id) {
                    $album->cover_photo_id = $photo->id;
                    $album->save();
                }
            }
        }

        if ($created === [] && $skipped === []) {
            throw new \InvalidArgumentException('アップロードできるファイルがありません');
        }

        if ($created !== []) {
            $this->forgetStorageStatsCache($userId);
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * クライアントと同じアルゴリズム（sa2-photo-v1）で内容フィンガープリントを計算する。
     * 4MB 以下は全文 SHA-256、それ以上は size + 先頭2MB + 末尾2MB。
     */
    public function computeContentHashFromPath(string $path): string
    {
        if (! is_file($path)) {
            throw new \InvalidArgumentException('ファイルが見つかりません');
        }

        $size = (int) filesize($path);
        $sample = 2 * 1024 * 1024;

        if ($size <= $sample * 2) {
            $hash = hash_file('sha256', $path);
            if (! is_string($hash) || $hash === '') {
                throw new \InvalidArgumentException('ハッシュの計算に失敗しました');
            }

            return $hash;
        }

        $fp = fopen($path, 'rb');
        if ($fp === false) {
            throw new \InvalidArgumentException('ファイルを開けません');
        }

        try {
            $head = fread($fp, $sample);
            if ($head === false || strlen($head) === 0) {
                throw new \InvalidArgumentException('ファイルの読み込みに失敗しました');
            }
            $headHash = hash('sha256', $head);

            $tailSize = min($sample, $size);
            if (fseek($fp, -$tailSize, SEEK_END) !== 0) {
                throw new \InvalidArgumentException('ファイル末尾の読み込みに失敗しました');
            }
            $tail = fread($fp, $tailSize);
            if ($tail === false || strlen($tail) === 0) {
                throw new \InvalidArgumentException('ファイル末尾の読み込みに失敗しました');
            }
            $tailHash = hash('sha256', $tail);
        } finally {
            fclose($fp);
        }

        return hash('sha256', 'sa2-photo-v1|'.$size.'|'.$headHash.'|'.$tailHash);
    }

    /**
     * 保存済みメディアの重複グループを返す（content_hash 未設定はストレージから計算）。
     *
     * @return list<array{hash: string, count: int, photos: list<array<string, mixed>>}>
     */
    public function findDuplicateGroups(int $userId, ?int $albumId = null): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        $query = Photo::query()->where('user_id', $userId);
        if ($albumId !== null) {
            $query->where('album_id', $albumId);
        }

        $photos = $query->orderByDesc('id')->get();
        /** @var array<string, list<Photo>> $byHash */
        $byHash = [];

        foreach ($photos as $photo) {
            $hash = is_string($photo->content_hash) ? strtolower($photo->content_hash) : '';
            if ($hash === '' || ! preg_match('/^[a-f0-9]{64}$/', $hash)) {
                try {
                    $hash = $this->computeContentHashForStoredPath((string) $photo->path);
                    // 他に同じハッシュが無ければバックフィル（ユニーク制約を守る）
                    if (! $this->findOwnedByContentHash($userId, $hash)) {
                        $photo->content_hash = $hash;
                        $photo->save();
                    }
                } catch (\Throwable $e) {
                    report($e);
                    continue;
                }
            }
            $byHash[$hash][] = $photo;
        }

        $groups = [];
        foreach ($byHash as $hash => $items) {
            if (count($items) < 2) {
                continue;
            }
            $groups[] = [
                'hash' => $hash,
                'count' => count($items),
                'photos' => array_map(
                    fn (Photo $photo) => $this->photoToArray($photo, $userId),
                    $items
                ),
            ];
        }

        usort($groups, static fn ($a, $b) => $b['count'] <=> $a['count']);

        return $groups;
    }

    public function computeContentHashForStoredPath(string $storagePath): string
    {
        $disk = $this->storage();
        if (! $disk->exists($storagePath)) {
            throw new \InvalidArgumentException(__('ファイルが見つかりません。'));
        }

        $size = (int) $disk->size($storagePath);
        $sample = 2 * 1024 * 1024;

        if ($this->usesObjectStorage()) {
            return $this->computeContentHashFromObjectStorage($storagePath, $size, $sample);
        }

        if (method_exists($disk, 'path')) {
            /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
            return $this->computeContentHashFromPath($disk->path($storagePath));
        }

        $tmp = tempnam(sys_get_temp_dir(), 'phash_');
        if ($tmp === false) {
            throw new \InvalidArgumentException('一時ファイルを作成できません');
        }
        try {
            $stream = $disk->readStream($storagePath);
            if ($stream === false) {
                throw new \InvalidArgumentException('ファイルを読み込めません');
            }
            $out = fopen($tmp, 'wb');
            if ($out === false) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                throw new \InvalidArgumentException('一時ファイルを開けません');
            }
            stream_copy_to_stream($stream, $out);
            fclose($out);
            if (is_resource($stream)) {
                fclose($stream);
            }

            return $this->computeContentHashFromPath($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    private function computeContentHashFromObjectStorage(string $path, int $size, int $sample): string
    {
        $disk = Storage::disk($this->diskName());
        $client = $disk->getClient();
        $bucket = (string) config('filesystems.disks.'.$this->diskName().'.bucket', '');
        if ($bucket === '' || ! is_object($client)) {
            throw new \InvalidArgumentException('ストレージ設定が不正です');
        }

        $prefix = (string) config('filesystems.disks.'.$this->diskName().'.root', '');
        $key = ($prefix !== '' ? rtrim($prefix, '/').'/' : '').ltrim($path, '/');

        if ($size <= $sample * 2) {
            $result = $client->getObject(['Bucket' => $bucket, 'Key' => $key]);
            $body = (string) $result['Body'];

            return hash('sha256', $body);
        }

        $head = (string) $client->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Range' => 'bytes=0-'.($sample - 1),
        ])['Body'];
        $tailStart = max(0, $size - $sample);
        $tail = (string) $client->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Range' => 'bytes='.$tailStart.'-'.($size - 1),
        ])['Body'];

        return hash('sha256', 'sa2-photo-v1|'.$size.'|'.hash('sha256', $head).'|'.hash('sha256', $tail));
    }

    public function updateOriginalName(int $userId, int $photoId, string $originalName): array
    {
        $photo = $this->findOwnedPhoto($userId, $photoId);
        if (! $photo) {
            throw new \InvalidArgumentException(__('写真が見つかりません'));
        }

        $name = trim($originalName);
        if ($name === '') {
            throw new \InvalidArgumentException(__('ファイル名を入力してください。'));
        }
        $photo->original_name = mb_substr($name, 0, 255);
        $photo->save();

        return $this->photoToArray($photo->fresh(), $userId);
    }

    public function findOwnedByContentHash(int $userId, string $hash): ?Photo
    {
        $hash = strtolower(trim($hash));
        if ($hash === '' || ! preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return null;
        }

        return Photo::query()
            ->where('user_id', $userId)
            ->where('content_hash', $hash)
            ->first();
    }

    /**
     * @param  list<string>  $hashes
     * @return list<string> 既存のハッシュ
     */
    public function findExistingContentHashes(int $userId, array $hashes): array
    {
        $normalized = [];
        foreach ($hashes as $hash) {
            if (! is_string($hash)) {
                continue;
            }
            $h = strtolower(trim($hash));
            if (preg_match('/^[a-f0-9]{64}$/', $h)) {
                $normalized[$h] = true;
            }
        }
        $list = array_keys($normalized);
        if ($list === []) {
            return [];
        }

        return Photo::query()
            ->where('user_id', $userId)
            ->whereIn('content_hash', $list)
            ->pluck('content_hash')
            ->map(static fn ($h) => strtolower((string) $h))
            ->unique()
            ->values()
            ->all();
    }

    public function receiveUploadChunk(
        int $userId,
        string $uploadId,
        int $chunkIndex,
        int $chunkTotal,
        UploadedFile $chunk
    ): void {
        $uploadId = $this->assertChunkUploadId($uploadId);
        if ($chunkIndex < 0 || $chunkTotal < 1 || $chunkIndex >= $chunkTotal) {
            throw new \InvalidArgumentException('チャンク情報が正しくありません');
        }
        if ($chunkTotal > 256) {
            throw new \InvalidArgumentException('ファイルが大きすぎます');
        }
        if (! $chunk->isValid()) {
            $this->throwUploadError($chunk);
        }
        // 4MBチャンク想定。multipart 余白込みで 12MB まで許容
        if ($chunk->getSize() > 12 * 1024 * 1024) {
            throw new \InvalidArgumentException('チャンクサイズが大きすぎます');
        }

        $dir = $this->chunkDir($userId, $uploadId);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \InvalidArgumentException('一時保存領域を作成できません');
        }

        $metaPath = $dir.DIRECTORY_SEPARATOR.'meta.json';
        if (is_file($metaPath)) {
            $meta = json_decode((string) file_get_contents($metaPath), true);
            if (is_array($meta) && (int) ($meta['total'] ?? 0) !== $chunkTotal) {
                throw new \InvalidArgumentException('チャンク総数が一致しません');
            }
        } else {
            file_put_contents($metaPath, json_encode([
                'total' => $chunkTotal,
                'created_at' => time(),
            ]));
        }

        $target = $dir.DIRECTORY_SEPARATOR.sprintf('part_%05d', $chunkIndex);
        if (! @move_uploaded_file($chunk->getRealPath(), $target) && ! @rename($chunk->getRealPath(), $target)) {
            $contents = @file_get_contents($chunk->getRealPath());
            if ($contents === false || file_put_contents($target, $contents) === false) {
                throw new \InvalidArgumentException('チャンクの保存に失敗しました');
            }
        }
    }

    /**
     * @return array{created: ?array<string, mixed>, skipped: bool, skippedName: ?string}
     */
    public function finalizeChunkedUpload(
        int $userId,
        string $uploadId,
        string $originalName,
        ?int $albumId = null,
        ?UploadedFile $videoThumb = null,
        ?string $mimeHint = null,
        bool $allowDuplicates = false,
        ?string $takenAtHint = null,
        ?string $contentHash = null
    ): array {
        $uploadId = $this->assertChunkUploadId($uploadId);
        $dir = $this->chunkDir($userId, $uploadId);
        $metaPath = $dir.DIRECTORY_SEPARATOR.'meta.json';
        if (! is_file($metaPath)) {
            throw new \InvalidArgumentException('アップロードセッションが見つかりません');
        }

        $meta = json_decode((string) file_get_contents($metaPath), true);
        $total = (int) ($meta['total'] ?? 0);
        if ($total < 1) {
            throw new \InvalidArgumentException('チャンク情報が不正です');
        }

        $parts = [];
        for ($i = 0; $i < $total; $i++) {
            $part = $dir.DIRECTORY_SEPARATOR.sprintf('part_%05d', $i);
            if (! is_file($part)) {
                throw new \InvalidArgumentException('欠落しているチャンクがあります（'.($i + 1).'/'.$total.'）');
            }
            $parts[] = $part;
        }

        $safeName = mb_substr(trim($originalName) !== '' ? $originalName : 'upload.bin', 0, 255);
        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION) ?: 'bin');
        $assembled = $dir.DIRECTORY_SEPARATOR.'assembled.'.$ext;
        $out = fopen($assembled, 'wb');
        if ($out === false) {
            throw new \InvalidArgumentException('結合用ファイルを作成できません');
        }
        try {
            foreach ($parts as $part) {
                $in = fopen($part, 'rb');
                if ($in === false) {
                    throw new \InvalidArgumentException('チャンクの読み込みに失敗しました');
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }

        $mime = is_string($mimeHint) && $mimeHint !== ''
            ? $mimeHint
            : (mime_content_type($assembled) ?: 'application/octet-stream');

        $uploaded = new UploadedFile($assembled, $safeName, $mime, \UPLOAD_ERR_OK, true);
        try {
            $result = $this->uploadPhotos(
                $userId,
                [$uploaded],
                $albumId,
                $videoThumb ? [0 => $videoThumb] : [],
                $allowDuplicates,
                $takenAtHint !== null && $takenAtHint !== '' ? [0 => $takenAtHint] : [],
                is_string($contentHash) && $contentHash !== '' ? [0 => $contentHash] : []
            );
        } finally {
            $this->deleteChunkDir($userId, $uploadId);
        }

        if (($result['created'][0] ?? null) !== null) {
            return [
                'created' => $result['created'][0],
                'skipped' => false,
                'skippedName' => null,
            ];
        }

        $skipped = $result['skipped'][0] ?? null;
        if ($skipped) {
            return [
                'created' => null,
                'skipped' => true,
                'skippedName' => $skipped['name'] ?? $safeName,
            ];
        }

        throw new \InvalidArgumentException('アップロードできるファイルがありません');
    }

    private function assertChunkUploadId(string $uploadId): string
    {
        $uploadId = trim($uploadId);
        if (! preg_match('/^[A-Za-z0-9_-]{8,80}$/', $uploadId)) {
            throw new \InvalidArgumentException('アップロードIDが不正です');
        }

        return $uploadId;
    }

    private function chunkDir(int $userId, string $uploadId): string
    {
        return storage_path('app/photo-chunks/'.$userId.'/'.$uploadId);
    }

    private function deleteChunkDir(int $userId, string $uploadId): void
    {
        $dir = $this->chunkDir($userId, $uploadId);
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function updateTakenAt(int $userId, int $photoId, ?string $takenAt): array
    {
        $photo = $this->findOwnedPhoto($userId, $photoId);
        if (! $photo) {
            throw new \InvalidArgumentException(__('写真が見つかりません'));
        }

        $normalized = $this->normalizeTakenAt($takenAt);
        if ($normalized === null) {
            throw new \InvalidArgumentException(__('登録日が正しくありません。'));
        }

        $photo->taken_at = $normalized;
        $photo->save();

        return $this->photoToArray($photo->fresh(), $userId);
    }

    private function normalizeTakenAt(?string $value): ?\Carbon\Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $raw = trim($value);
        $tz = config('app.timezone', 'Asia/Tokyo');

        foreach (['Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d'] as $format) {
            try {
                $carbon = \Carbon\Carbon::createFromFormat($format, $raw, $tz);
                if ($carbon !== false) {
                    if ($format === 'Y-m-d') {
                        $carbon->setTime(12, 0, 0);
                    }

                    return $carbon;
                }
            } catch (\Throwable) {
                // try next format
            }
        }

        try {
            return \Carbon\Carbon::parse($raw, $tz);
        } catch (\Throwable) {
            return null;
        }
    }

    public function deletePhoto(int $userId, int $photoId): bool
    {
        return $this->bulkDeletePhotos($userId, [$photoId]) === 1;
    }

    /** @param list<int> $ids */
    public function bulkArchivePhotos(int $userId, array $ids): int
    {
        $idSet = $this->parseIdList($ids);
        if ($idSet === []) {
            return 0;
        }

        return Photo::query()
            ->where('user_id', $userId)
            ->whereIn('id', $idSet)
            ->whereNull('archived_at')
            ->update(['archived_at' => now()]);
    }

    /** @param list<int> $ids */
    public function bulkRestorePhotos(int $userId, array $ids): int
    {
        $idSet = $this->parseIdList($ids);
        if ($idSet === []) {
            return 0;
        }

        return Photo::query()
            ->where('user_id', $userId)
            ->whereIn('id', $idSet)
            ->whereNotNull('archived_at')
            ->update([
                'archived_at' => null,
                // 復元先は元アルバムではなく「すべて」（アルバム未所属）
                'album_id' => null,
            ]);
    }

    /** @param list<int> $ids */
    public function bulkDeletePhotos(int $userId, array $ids): int
    {
        $idSet = $this->parseIdList($ids);
        if ($idSet === []) {
            return 0;
        }

        $photos = Photo::query()
            ->where('user_id', $userId)
            ->whereIn('id', $idSet)
            ->get(['id', 'path', 'thumb_path', 'cloudinary_public_id', 'cold_disk', 'cold_path']);

        if ($photos->isEmpty()) {
            return 0;
        }

        $paths = [];
        foreach ($photos as $photo) {
            if (is_string($photo->path) && $photo->path !== '') {
                $paths[] = $photo->path;
            }
            if (is_string($photo->thumb_path) && $photo->thumb_path !== '' && $photo->thumb_path !== $photo->path) {
                $paths[] = $photo->thumb_path;
            }
            if (is_string($photo->cloudinary_public_id) && $photo->cloudinary_public_id !== '') {
                $this->cloudinary->deletePhoto(
                    $photo->cloudinary_public_id,
                    $photo->cloudinary_resource_type
                );
            }
            if (is_string($photo->cold_path) && $photo->cold_path !== '' && is_string($photo->cold_disk) && $photo->cold_disk !== '') {
                try {
                    Storage::disk($photo->cold_disk)->delete($photo->cold_path);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        $this->deleteStoragePaths(array_values(array_unique($paths)));

        $photoIds = $photos->pluck('id')->map(static fn ($id) => (int) $id)->all();

        PhotoAlbum::query()
            ->where('user_id', $userId)
            ->whereIn('cover_photo_id', $photoIds)
            ->update(['cover_photo_id' => null]);

        Photo::query()
            ->where('user_id', $userId)
            ->whereIn('id', $photoIds)
            ->delete();

        $this->forgetStorageStatsCache($userId);

        return count($photoIds);
    }

    /** @param list<string> $paths */
    /** @param list<string|null> $paths */
    private function deleteStoragePaths(array $paths, ?string $diskName = null): void
    {
        $paths = array_values(array_filter($paths, static fn ($p) => is_string($p) && $p !== ''));
        if ($paths === []) {
            return;
        }

        $diskNames = array_values(array_unique(array_filter([
            $diskName,
            $this->diskName(),
        ])));

        foreach ($diskNames as $name) {
            $disk = Storage::disk($name);
            if ($name === $this->diskName() && $this->usesObjectStorage() && $this->deleteObjectStoragePathsBatched($paths)) {
                continue;
            }
            foreach (array_chunk($paths, 100) as $chunk) {
                try {
                    $disk->delete($chunk);
                } catch (\Throwable) {
                    foreach ($chunk as $path) {
                        try {
                            $disk->delete($path);
                        } catch (\Throwable) {
                            // 欠落ファイルは無視
                        }
                    }
                }
            }
        }
    }

    /**
     * @return array{disk: string, tier: string}
     */
    private function resolveUploadTarget(int $userId, int $incomingBytes): array
    {
        $primary = $this->diskName();
        $mode = $this->mediaConfig->capacityMode();

        if ($mode === MediaStorageConfigService::CAPACITY_MODE_R2_CAP) {
            return $this->resolveR2CapUploadTarget($userId, $incomingBytes, $primary);
        }

        if ($mode !== MediaStorageConfigService::CAPACITY_MODE_OVERFLOW) {
            return ['disk' => $primary, 'tier' => 'hot'];
        }

        $r2Quota = $this->userQuotaBytes();
        $b2Quota = $this->b2QuotaBytes();
        $hotUsed = (int) Photo::query()
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->whereNull('storage_tier')->orWhere('storage_tier', 'hot');
            })
            ->sum('size_bytes');
        $coldUsed = (int) Photo::query()
            ->where('user_id', $userId)
            ->where('storage_tier', 'cold')
            ->sum('size_bytes');

        if ($hotUsed + $incomingBytes <= $r2Quota) {
            return ['disk' => $primary, 'tier' => 'hot'];
        }

        if ($this->mediaConfig->backblazeEnabled() && $coldUsed + $incomingBytes <= $b2Quota) {
            return ['disk' => 'backblaze', 'tier' => 'cold'];
        }

        $overflow = $this->mediaConfig->overflowDisk();
        if ($overflow === 'backblaze' && $this->mediaConfig->backblazeEnabled()) {
            return ['disk' => 'backblaze', 'tier' => 'cold'];
        }
        if ($overflow === 'r2') {
            return ['disk' => 'r2', 'tier' => 'hot'];
        }

        // サーバーローカルは primary と別ディスクになるため overflow ティアで保持
        return ['disk' => 'public', 'tier' => 'overflow'];
    }

    /**
     * モード1: R2（常用）が無料枠を超えそうなら原本を B2 直書き。サムネは常に主ディスク。
     *
     * @return array{disk: string, tier: string}
     */
    private function resolveR2CapUploadTarget(int $userId, int $incomingBytes, string $primary): array
    {
        $quota = $this->userQuotaBytes();
        $hotUsed = (int) Photo::query()
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->whereNull('storage_tier')->orWhere('storage_tier', 'hot');
            })
            ->sum('size_bytes');
        // 使用状況表示と同じく「ホット原本 + 全サムネ概算」、新規サムネ分も見込む
        $thumbExtra = (int) Photo::query()
            ->where('user_id', $userId)
            ->whereNotNull('thumb_path')
            ->count() * 80_000;
        $projectedHot = $hotUsed + $thumbExtra + max(0, $incomingBytes) + 80_000;

        if ($projectedHot <= $quota) {
            return ['disk' => $primary, 'tier' => 'hot'];
        }

        if (
            $this->mediaConfig->backblazeEnabled()
            && $this->mediaConfig->pipelineArchivesToBackblaze()
        ) {
            return ['disk' => 'backblaze', 'tier' => 'cold'];
        }

        // B2 未設定時は従来どおり主ディスク（後追いアーカイブに任せる）
        return ['disk' => $primary, 'tier' => 'hot'];
    }

    /** @param list<string> $paths */
    private function deleteObjectStoragePathsBatched(array $paths): bool
    {
        try {
            $disk = Storage::disk($this->diskName());
            $client = $disk->getClient();
            $bucket = (string) config('filesystems.disks.'.$this->diskName().'.bucket', '');
            if ($bucket === '' || ! is_object($client) || ! method_exists($client, 'deleteObjects')) {
                return false;
            }

            $prefix = (string) config('filesystems.disks.'.$this->diskName().'.root', '');
            $prefix = $prefix !== '' ? rtrim($prefix, '/').'/' : '';

            foreach (array_chunk($paths, 1000) as $chunk) {
                $objects = [];
                foreach ($chunk as $path) {
                    $objects[] = ['Key' => $prefix.ltrim($path, '/')];
                }

                $client->deleteObjects([
                    'Bucket' => $bucket,
                    'Delete' => [
                        'Objects' => $objects,
                        'Quiet' => true,
                    ],
                ]);
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param list<int> $ids */
    public function bulkMovePhotos(int $userId, array $ids, ?int $albumId): int
    {
        $idSet = $this->parseIdList($ids);
        if ($idSet === []) {
            return 0;
        }

        if ($albumId !== null) {
            $album = PhotoAlbum::query()->where('user_id', $userId)->find($albumId);
            if (! $album) {
                throw new \InvalidArgumentException('アルバムが見つかりません');
            }
        }

        $photos = Photo::query()
            ->where('user_id', $userId)
            ->whereIn('id', $idSet)
            ->get();

        $moved = 0;
        foreach ($photos as $photo) {
            $oldAlbumId = $photo->album_id ? (int) $photo->album_id : null;
            $photo->album_id = $albumId;
            $photo->save();
            $moved++;

            if ($oldAlbumId && $oldAlbumId !== $albumId) {
                PhotoAlbum::query()
                    ->where('user_id', $userId)
                    ->where('id', $oldAlbumId)
                    ->where('cover_photo_id', $photo->id)
                    ->update(['cover_photo_id' => null]);
            }
        }

        return $moved;
    }

    /** @param mixed $raw @return list<int> */
    public function parseIdList(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $list = is_array($raw) ? $raw : [$raw];

        return array_values(array_unique(array_filter(
            array_map(static fn ($v) => (int) $v, $list),
            static fn ($id) => $id > 0
        )));
    }

    public function deleteAlbum(int $userId, int $albumId): string
    {
        $album = PhotoAlbum::query()->where('user_id', $userId)->find($albumId);
        if (! $album) {
            return 'not_found';
        }

        // 削除可否は通常表示と同じく、アーカイブ済みは数えない
        $photoCount = Photo::query()
            ->where('user_id', $userId)
            ->where('album_id', $albumId)
            ->whereNull('archived_at')
            ->count();

        if ($photoCount > 0) {
            return 'not_empty';
        }

        $album->delete();

        return 'deleted';
    }

    private function assertValidUpload(UploadedFile $file): void
    {
        $mime = (string) $file->getMimeType();
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $isVideo = $this->isVideoMime($mime, $ext);

        $max = $isVideo ? $this->maxVideoUploadBytes() : $this->maxUploadBytes();
        if ($max > 0 && $file->getSize() > $max) {
            throw new \InvalidArgumentException(
                ($isVideo ? '動画' : '画像').'は'.$this->formatBytes($max).'以下にしてください'
            );
        }

        $okImageExt = in_array($ext, self::ALLOWED_IMAGE_EXTENSIONS, true);
        $okVideoExt = in_array($ext, self::ALLOWED_VIDEO_EXTENSIONS, true);
        $okMime = in_array($mime, self::ALLOWED_MIMES, true) || in_array($mime, self::ALLOWED_VIDEO_MIMES, true);

        if (! $okMime && ! ($isVideo ? $okVideoExt : $okImageExt)) {
            // 一部環境では動画が application/octet-stream になる
            if ($okVideoExt || $okImageExt) {
                return;
            }
            throw new \InvalidArgumentException('対応形式は JPEG / PNG / WebP / GIF / HEIC / MP4 / MOV / AVI です');
        }
    }

    private function throwUploadError(UploadedFile $file): void
    {
        $code = $file->getError();
        if (in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new \InvalidArgumentException(
                'ファイルが PHP のアップロード上限を超えています（upload_max_filesize='
                .ini_get('upload_max_filesize')
                .' / post_max_size='
                .ini_get('post_max_size')
                .'）。900M 以上に上げてサーバーを再起動してください。'
            );
        }
        if ($code !== UPLOAD_ERR_NO_FILE) {
            throw new \InvalidArgumentException('ファイルのアップロードに失敗しました（エラーコード: '.$code.'）');
        }
    }

    /**
     * MP4 / MOV / AVI を保存し、クライアント生成サムネがあればそれを使う（なければ仮サムネ）。
     *
     * @return array{path: string, thumbPath: ?string, mime: string, sizeBytes: int, width: ?int, height: ?int}|null
     */
    private function storeVideo(UploadedFile $file, string $dir, ?UploadedFile $thumbFile = null, ?string $diskName = null): ?array
    {
        // 動画を丸ごとメモリに載せない（ストリーム転送）
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '1024M');
        }

        $writeDisk = Storage::disk($diskName ?: $this->diskName());
        $basename = str_replace('.', '', uniqid('vid_', true));
        [$extension, $mime] = $this->videoFormatFor($file);
        $filename = $basename.'.'.$extension;
        $dir = trim($dir, '/');

        try {
            $path = $writeDisk->putFileAs($dir, $file, $filename, [
                'visibility' => $this->objectVisibility($diskName),
                'ContentType' => $mime,
                'mimetype' => $mime,
            ]);
        } catch (\Throwable $e) {
            report($e);
            throw new \InvalidArgumentException(
                '動画の保存に失敗しました。サイズを小さくするか、しばらくして再試行してください。'
            );
        }

        if (! is_string($path) || $path === '') {
            throw new \InvalidArgumentException(
                '動画の保存に失敗しました。ストレージ設定（R2 / ディスク）を確認してください。'
            );
        }

        $width = 1280;
        $height = 720;
        // サムネは常にホット（主ディスク）へ
        $thumbPath = $this->storeUploadedVideoThumb($thumbFile, $dir.'/'.$basename.'_thumb.jpg', $width, $height)
            ?? $this->storeVideoPlaceholderThumb($dir.'/'.$basename.'_thumb.jpg');

        return [
            'path' => $path,
            'thumbPath' => $thumbPath,
            'mime' => $mime,
            'sizeBytes' => (int) $file->getSize(),
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * 保存する拡張子と MIME。判別できないものは MP4 として扱う。
     *
     * @return array{0: string, 1: string}
     */
    private function videoFormatFor(UploadedFile $file): array
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());

        if ($ext === 'mov' || str_starts_with($mime, 'video/quicktime')) {
            return ['mov', 'video/quicktime'];
        }

        if ($ext === 'avi' || preg_match('#^video/(x-msvideo|avi|msvideo)#', $mime) === 1) {
            return ['avi', 'video/x-msvideo'];
        }

        return ['mp4', 'video/mp4'];
    }

    private function storeUploadedVideoThumb(?UploadedFile $thumbFile, string $thumbPath, int &$width, int &$height): ?string
    {
        if (! $thumbFile instanceof UploadedFile || ! $thumbFile->isValid()) {
            return null;
        }
        $mime = strtolower((string) $thumbFile->getMimeType());
        if (! str_contains($mime, 'jpeg') && ! str_contains($mime, 'jpg') && ! str_contains($mime, 'png')) {
            return null;
        }
        if ($thumbFile->getSize() > 5 * 1024 * 1024) {
            return null;
        }

        $source = $thumbFile->getRealPath();
        if (! $source) {
            return null;
        }
        $size = @getimagesize($source);
        if (is_array($size) && ($size[0] ?? 0) > 0 && ($size[1] ?? 0) > 0) {
            $width = (int) $size[0];
            $height = (int) $size[1];
        }

        try {
            $this->putFileContents($thumbPath, (string) file_get_contents($source), 'image/jpeg');
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return $thumbPath;
    }

    private function storeVideoPlaceholderThumb(string $thumbPath): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $w = 640;
        $h = 360;
        $im = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($im, 26, 31, 36);
        $accent = imagecolorallocate($im, 47, 111, 126);
        $white = imagecolorallocate($im, 245, 247, 248);
        imagefilledrectangle($im, 0, 0, $w, $h, $bg);

        // 再生ボタン（三角）
        $cx = (int) ($w / 2);
        $cy = (int) ($h / 2);
        $r = 48;
        imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $accent);
        $triangle = [
            $cx - 14, $cy - 22,
            $cx - 14, $cy + 22,
            $cx + 24, $cy,
        ];
        imagefilledpolygon($im, $triangle, $white);

        $tmp = tempnam(sys_get_temp_dir(), 'vth');
        if ($tmp === false) {
            imagedestroy($im);

            return null;
        }
        imagejpeg($im, $tmp, 82);
        imagedestroy($im);

        try {
            $this->putFileContents($thumbPath, (string) file_get_contents($tmp), 'image/jpeg');
        } finally {
            @unlink($tmp);
        }

        return $thumbPath;
    }

    /**
     * 画像を解像度そのまま保存し、一覧用サムネだけ生成する。
     * ローカル / R2（S3互換）どちらでも動くよう putFileAs / 一時ファイル経由で put する。
     *
     * @return array{path: string, thumbPath: ?string, mime: string, sizeBytes: int, width: ?int, height: ?int}|null
     */
    private function storeOptimizedImage(UploadedFile $file, string $dir, ?string $diskName = null): ?array
    {
        $sourcePath = $file->getRealPath();
        if (! $sourcePath) {
            return null;
        }

        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '1024M');
        }

        $writeDisk = Storage::disk($diskName ?: $this->diskName());
        $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
        $ext = $this->imageStorageExtension($file, $mime);
        $basename = str_replace('.', '', uniqid('ph_', true));
        $dir = trim($dir, '/');
        $filename = $basename.'.'.$ext;
        $thumbRel = $dir.'/'.$basename.'_thumb.jpg';

        try {
            $path = $writeDisk->putFileAs($dir, $file, $filename, [
                'visibility' => $this->objectVisibility($diskName),
                'ContentType' => $mime,
                'mimetype' => $mime,
            ]);
        } catch (\Throwable $e) {
            report($e);
            throw new \InvalidArgumentException(
                '画像の保存に失敗しました（'.($diskName ?: $this->diskName()).'）: '.mb_substr($e->getMessage(), 0, 180)
            );
        }

        if (! is_string($path) || $path === '') {
            throw new \InvalidArgumentException(
                '画像の保存に失敗しました。ストレージ設定（R2）の接続テストを確認してください。'
            );
        }

        $width = null;
        $height = null;
        $meta = @getimagesize($sourcePath);
        if (is_array($meta)) {
            $width = isset($meta[0]) ? (int) $meta[0] : null;
            $height = isset($meta[1]) ? (int) $meta[1] : null;
        }

        $thumbEdge = max(240, (int) config('photos.thumb_long_edge', 720));
        $quality = max(40, min(95, (int) config('photos.jpeg_quality', 82)));
        // サムネ失敗でアップロード全体を落とさない（高解像度スマホ写真対策）
        $thumbPath = null;
        try {
            $thumbPath = $this->writeImageThumbnail($sourcePath, $mime, $thumbRel, $thumbEdge, $quality, $width, $height);
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            $sizeBytes = (int) $writeDisk->size($path);
        } catch (\Throwable) {
            $sizeBytes = (int) $file->getSize();
        }

        return [
            'path' => $path,
            'thumbPath' => $thumbPath,
            'mime' => $mime,
            'sizeBytes' => $sizeBytes > 0 ? $sizeBytes : (int) $file->getSize(),
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * 超高解像度は GD フルデコードすると OOM するため、Imagick → EXIF サムネ → GD（安全な画素数のみ）の順で作る。
     */
    private function writeImageThumbnail(
        string $sourcePath,
        string $mime,
        string $thumbRel,
        int $thumbEdge,
        int $quality,
        ?int $width,
        ?int $height,
    ): ?string {
        if ($this->writeThumbWithImagick($sourcePath, $thumbRel, $thumbEdge, $quality)) {
            return $thumbRel;
        }

        if ($this->writeThumbFromExif($sourcePath, $thumbRel, $thumbEdge, $quality)) {
            return $thumbRel;
        }

        $pixels = max(0, (int) $width) * max(0, (int) $height);
        $gdMax = max(1_000_000, (int) config('photos.gd_max_source_pixels', 36_000_000));
        if ($pixels > 0 && $pixels > $gdMax) {
            return null;
        }

        if ($this->writeThumbWithGd($sourcePath, $mime, $thumbRel, $thumbEdge, $quality)) {
            return $thumbRel;
        }

        return null;
    }

    private function writeThumbWithImagick(string $sourcePath, string $thumbRel, int $thumbEdge, int $quality): bool
    {
        if (! extension_loaded('imagick') || ! class_exists(\Imagick::class)) {
            return false;
        }

        try {
            $img = new \Imagick($sourcePath);
            if (method_exists($img, 'autoOrient')) {
                $img->autoOrient();
            }
            $img->thumbnailImage($thumbEdge, $thumbEdge, true);
            $img->setImageFormat('jpeg');
            $img->setImageCompressionQuality($quality);
            $blob = $img->getImageBlob();
            $img->clear();
            $img->destroy();
            if (! is_string($blob) || $blob === '') {
                return false;
            }
            $this->putFileContents($thumbRel, $blob, 'image/jpeg');

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    private function writeThumbFromExif(string $sourcePath, string $thumbRel, int $thumbEdge, int $quality): bool
    {
        if (! function_exists('exif_thumbnail')) {
            return false;
        }

        try {
            $tw = 0;
            $th = 0;
            $tt = 0;
            $data = @exif_thumbnail($sourcePath, $tw, $th, $tt);
            if (! is_string($data) || $data === '') {
                return false;
            }

            $src = @imagecreatefromstring($data);
            if ($src === false) {
                // EXIF サムネをそのまま保存
                $this->putFileContents($thumbRel, $data, 'image/jpeg');

                return true;
            }

            $sw = imagesx($src);
            $sh = imagesy($src);
            if ($sw < 1 || $sh < 1) {
                imagedestroy($src);

                return false;
            }

            [$dw, $dh] = $this->scaledSize($sw, $sh, $thumbEdge);
            $dst = imagecreatetruecolor($dw, $dh);
            $this->fillWhite($dst);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
            imagedestroy($src);
            $tmp = tempnam(sys_get_temp_dir(), 'phe');
            if ($tmp === false) {
                imagedestroy($dst);

                return false;
            }
            try {
                imagejpeg($dst, $tmp, $quality);
                $this->putFileContents($thumbRel, (string) file_get_contents($tmp), 'image/jpeg');
            } finally {
                imagedestroy($dst);
                @unlink($tmp);
            }

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    private function writeThumbWithGd(string $sourcePath, string $mime, string $thumbRel, int $thumbEdge, int $quality): bool
    {
        $src = $this->createImageResource($sourcePath, $mime);
        if (! $src) {
            return false;
        }

        try {
            $sw = imagesx($src);
            $sh = imagesy($src);
            if ($sw < 1 || $sh < 1) {
                return false;
            }
            [$tw, $th] = $this->scaledSize($sw, $sh, $thumbEdge);
            $thumb = imagecreatetruecolor($tw, $th);
            $this->fillWhite($thumb);
            imagecopyresampled($thumb, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);
            $thumbTmp = tempnam(sys_get_temp_dir(), 'pht');
            if ($thumbTmp === false) {
                imagedestroy($thumb);

                return false;
            }
            try {
                imagejpeg($thumb, $thumbTmp, $quality);
                // 原本が B2 でも、一覧サムネは常に主ディスク（R2）へ
                $this->putFileContents($thumbRel, (string) file_get_contents($thumbTmp), 'image/jpeg');
            } finally {
                imagedestroy($thumb);
                @unlink($thumbTmp);
            }

            return true;
        } finally {
            imagedestroy($src);
        }
    }

    private function imageStorageExtension(UploadedFile $file, string $mime): string
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'], true)) {
            return $ext === 'jpeg' ? 'jpg' : $ext;
        }

        return match (true) {
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'gif') => 'gif',
            str_contains($mime, 'heic') => 'heic',
            str_contains($mime, 'heif') => 'heif',
            default => 'bin',
        };
    }

    private function storage(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk($this->diskName());
    }

    private function putFileContents(string $path, string $contents, string $contentType): void
    {
        $options = [
            'visibility' => $this->objectVisibility(),
            'ContentType' => $contentType,
        ];
        $this->storage()->put($path, $contents, $options);
    }

    /**
     * R2 / B2 はオブジェクト ACL（public-read）非対応のため private で保存し、配信はアプリ側 URL で行う。
     */
    private function objectVisibility(?string $diskName = null): string
    {
        $name = $diskName ?: $this->diskName();
        if (in_array($name, ['r2', 'backblaze'], true)) {
            return 'private';
        }

        $driver = (string) config('filesystems.disks.'.$name.'.driver', 'local');

        return $driver === 's3' ? 'private' : 'public';
    }

    /** @return \GdImage|false|resource */
    private function createImageResource(string $sourcePath, string $mime)
    {
        if (! function_exists('imagecreatetruecolor')) {
            return false;
        }

        return match (true) {
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => @imagecreatefromjpeg($sourcePath),
            str_contains($mime, 'png') => @imagecreatefrompng($sourcePath),
            str_contains($mime, 'webp') && function_exists('imagecreatefromwebp') => @imagecreatefromwebp($sourcePath),
            str_contains($mime, 'gif') => @imagecreatefromgif($sourcePath),
            default => false,
        };
    }

    /** @return array{0: int, 1: int} */
    private function scaledSize(int $width, int $height, int $maxEdge): array
    {
        $scale = min(1, $maxEdge / max($width, $height));

        return [
            max(1, (int) round($width * $scale)),
            max(1, (int) round($height * $scale)),
        ];
    }

    /** @param \GdImage|resource $image */
    private function fillWhite($image): void
    {
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, imagesx($image), imagesy($image), $white);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1).' MB';
        }
        if ($bytes < 1024 * 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 2).' GB';
        }

        $tb = $bytes / (1024 * 1024 * 1024 * 1024);
        $decimals = $tb >= 10 ? 1 : 2;

        return round($tb, $decimals).' TB';
    }

    /**
     * Save an edited image as a new photo (original untouched).
     */
    public function findOwnedPhoto(int $userId, int $photoId): ?Photo
    {
        return Photo::query()->where('user_id', $userId)->find($photoId);
    }

    public function findViewablePhoto(int $userId, int $photoId): ?Photo
    {
        $photo = Photo::query()->with('album')->find($photoId);
        if (! $photo) {
            return null;
        }
        if ((int) $photo->user_id === $userId) {
            return $photo;
        }
        if ($photo->album_id) {
            $album = $photo->album ?: $this->findViewableAlbum($userId, (int) $photo->album_id);
            if ($album && $this->canViewAlbum($userId, $album)) {
                return $photo;
            }
        }

        return null;
    }

    /** @return array{contents: string, mime: string, name: string} */
    public function readPhotoFile(Photo $photo, string $variant = 'original'): array
    {
        $wantThumb = $variant === 'thumb';
        $hasThumb = is_string($photo->thumb_path) && $photo->thumb_path !== '';
        $isVideo = $this->isVideoMime((string) ($photo->mime ?? ''), pathinfo((string) $photo->path, PATHINFO_EXTENSION));

        if ($wantThumb && ! $hasThumb && $isVideo) {
            return $this->videoMissingThumbPayload($photo);
        }

        $path = $wantThumb && $hasThumb
            ? (string) $photo->thumb_path
            : (string) $photo->path;

        // サムネはホット（主ディスク）優先
        if ($wantThumb && $path === (string) $photo->thumb_path && $path !== (string) $photo->path) {
            $diskName = $this->diskName();
            $disk = $this->storage();
        } else {
            $diskName = $this->diskForPhoto($photo);
            $disk = $this->storageForPhoto($photo);
            if (! $disk->exists($path) && in_array(($photo->storage_tier ?? 'hot'), ['cold', 'overflow'], true)) {
                $alt = (string) ($photo->cold_path ?: '');
                if ($alt !== '' && $disk->exists($alt)) {
                    $path = $alt;
                }
            }
            if (! $disk->exists($path)) {
                $diskName = $this->diskName();
                $disk = $this->storage();
            }
        }

        if (! $disk->exists($path)) {
            if ($wantThumb && $isVideo) {
                return $this->videoMissingThumbPayload($photo);
            }
            throw new \InvalidArgumentException(__('ファイルが見つかりません。'));
        }

        $contents = $disk->get($path);
        if ($diskName === 'backblaze') {
            $bytes = is_string($contents) ? strlen($contents) : (int) ($photo->size_bytes ?? 0);
            $this->mediaConfig->recordB2Usage($bytes, 1, 0);
        }

        $mime = $wantThumb && $path === (string) $photo->thumb_path
            ? 'image/jpeg'
            : (string) ($photo->mime ?: 'application/octet-stream');
        $name = $wantThumb && $path === (string) $photo->thumb_path
            ? (pathinfo((string) ($photo->original_name ?: 'thumb'), PATHINFO_FILENAME).'_thumb.jpg')
            : (string) ($photo->original_name ?: basename($path));

        return [
            'contents' => $contents,
            'mime' => $mime,
            'name' => $name,
        ];
    }

    /** @return array{contents: string, mime: string, name: string} */
    private function videoMissingThumbPayload(Photo $photo): array
    {
        $contents = null;
        if (function_exists('imagecreatetruecolor')) {
            $w = 640;
            $h = 360;
            $im = imagecreatetruecolor($w, $h);
            $bg = imagecolorallocate($im, 42, 49, 56);
            $accent = imagecolorallocate($im, 47, 111, 126);
            $white = imagecolorallocate($im, 245, 247, 248);
            imagefilledrectangle($im, 0, 0, $w, $h, $bg);
            $cx = (int) ($w / 2);
            $cy = (int) ($h / 2);
            imagefilledellipse($im, $cx, $cy, 96, 96, $accent);
            imagefilledpolygon($im, [$cx - 14, $cy - 22, $cx - 14, $cy + 22, $cx + 24, $cy], $white);
            ob_start();
            imagejpeg($im, null, 82);
            $contents = ob_get_clean();
            imagedestroy($im);
        }

        if (! is_string($contents) || $contents === '') {
            // 最小の 1x1 JPEG
            $contents = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAGcP//EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAQUCf//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQMBAT8Bf//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQIBAT8Bf//Z');
        }

        return [
            'contents' => $contents,
            'mime' => 'image/jpeg',
            'name' => pathinfo((string) ($photo->original_name ?: 'video'), PATHINFO_FILENAME).'_thumb.jpg',
        ];
    }

    public function saveEditedImage(int $userId, int $photoId, UploadedFile $image, ?string $label = null): array
    {
        $source = Photo::query()->where('user_id', $userId)->find($photoId);
        if (! $source) {
            throw new \InvalidArgumentException(__('写真が見つかりません'));
        }
        if ($this->isVideoMime((string) $source->mime, pathinfo((string) $source->path, PATHINFO_EXTENSION))) {
            throw new \InvalidArgumentException(__('動画は画像編集できません。動画トリムを使ってください。'));
        }

        $this->assertWithinFreeQuotaOrPaid($userId, max(0, (int) $image->getSize()));

        $dir = 'photos/'.$userId;
        $stored = $this->storeOptimizedImage($image, $dir);
        if (! $stored) {
            throw new \InvalidArgumentException(__('編集画像の保存に失敗しました。'));
        }

        $minSort = $this->allocateFrontPhotoSortOrder($userId);
        $photo = Photo::create([
            'user_id' => $userId,
            'album_id' => $source->album_id,
            'parent_photo_id' => $source->id,
            'path' => $stored['path'],
            'thumb_path' => $stored['thumbPath'],
            'original_name' => $source->original_name,
            'mime' => $stored['mime'],
            'size_bytes' => $stored['sizeBytes'],
            'width' => $stored['width'],
            'height' => $stored['height'],
            'caption' => $source->caption,
            'edit_label' => $label ? mb_substr(trim($label), 0, 120) : __('編集版'),
            'taken_at' => $source->taken_at,
            'sort_order' => $minSort,
            'storage_tier' => 'hot',
        ]);

        // 表示用の常設 Cloudinary 同期はしない（編集専用方針）
        if ($this->mediaConfig->pipelineUsesCloudinaryDisplay()) {
            $this->maybeSyncCloudinary($photo);
        }

        return $this->photoToArray($photo->fresh() ?? $photo, $userId);
    }

    /**
     * 鮮明化設定の使用エンジンでアップスケールし、結果を R2（現行 photos.disk）へ新規保存する。元画像は残す。
     *
     * @return array{photo: array<string, mixed>, sourceWidth: ?int, sourceHeight: ?int, resultWidth: ?int, resultHeight: ?int}
     */
    public function enhancePhoto(int $userId, int $photoId): array
    {
        $enhance = app(EnhanceConfigService::class);
        if (! $enhance->isReady()) {
            throw new \InvalidArgumentException(__(':name が利用できません。鮮明化設定を確認してください。', [
                'name' => $enhance->providerLabel($enhance->activeProvider()),
            ]));
        }

        $cancel = app(EnhanceCancelService::class);
        $cancel->begin($userId, $photoId);

        try {
            return match ($enhance->activeProvider()) {
                EnhanceConfigService::PROVIDER_REALESRGAN => $this->enhanceWithRealEsrgan($userId, $photoId),
                EnhanceConfigService::PROVIDER_SWINIR => $this->enhanceWithSwinIr($userId, $photoId),
                default => $this->enhanceWithStability($userId, $photoId),
            };
        } finally {
            $cancel->clear($userId, $photoId);
        }
    }

    public function cancelEnhance(int $userId, int $photoId): void
    {
        app(EnhanceCancelService::class)->requestCancel($userId, $photoId);

        try {
            app(SwinIrService::class)->requestRemoteCancel();
        } catch (\Throwable) {
            // ワーカー未起動時は無視（ローカル Abort とキャッシュで十分）
        }
    }

    /**
     * Stability AI で鮮明化し、結果を R2（現行 photos.disk）へ新規保存する。元画像は残す。
     *
     * @return array{photo: array<string, mixed>, sourceWidth: ?int, sourceHeight: ?int, resultWidth: ?int, resultHeight: ?int}
     */
    public function enhanceWithStability(int $userId, int $photoId): array
    {
        if (! $this->mediaConfig->stabilityEnabled()) {
            throw new \InvalidArgumentException(__('Stability AI が有効ではありません。鮮明化設定を確認してください。'));
        }

        $source = Photo::query()->where('user_id', $userId)->find($photoId);
        if (! $source) {
            throw new \InvalidArgumentException(__('写真が見つかりません'));
        }
        if ($this->isVideoMime((string) $source->mime, pathinfo((string) $source->path, PATHINFO_EXTENSION))) {
            throw new \InvalidArgumentException(__('動画は AI 鮮明化の対象外です。'));
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        $sourceWidth = $source->width ? (int) $source->width : null;
        $sourceHeight = $source->height ? (int) $source->height : null;

        $file = $this->readPhotoFile($source);
        $enhanced = $this->stability->enhanceImage(
            $file['contents'],
            $file['name'],
            $file['mime']
        );

        return $this->persistEnhancedPhoto(
            $userId,
            $photoId,
            $enhanced,
            'stability-enhance',
            $sourceWidth,
            $sourceHeight
        );
    }

    /**
     * Real-ESRGAN（ローカル GPU）で鮮明化し、結果を R2 へ新規保存する。元画像は残す。
     *
     * @return array{photo: array<string, mixed>, sourceWidth: ?int, sourceHeight: ?int, resultWidth: ?int, resultHeight: ?int}
     */
    public function enhanceWithRealEsrgan(int $userId, int $photoId): array
    {
        if (! $this->mediaConfig->realesrganEnabled()) {
            throw new \InvalidArgumentException(__('Real-ESRGAN が有効ではありません。鮮明化設定を確認してください。'));
        }

        $source = Photo::query()->where('user_id', $userId)->find($photoId);
        if (! $source) {
            throw new \InvalidArgumentException(__('写真が見つかりません'));
        }
        if ($this->isVideoMime((string) $source->mime, pathinfo((string) $source->path, PATHINFO_EXTENSION))) {
            throw new \InvalidArgumentException(__('動画は AI 鮮明化の対象外です。'));
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(900);
        }

        $sourceWidth = $source->width ? (int) $source->width : null;
        $sourceHeight = $source->height ? (int) $source->height : null;

        $file = $this->readPhotoFile($source);
        $enhanced = app(RealEsrganService::class)->enhanceImage(
            $file['contents'],
            $file['name'],
            $file['mime']
        );

        return $this->persistEnhancedPhoto(
            $userId,
            $photoId,
            $enhanced,
            'realesrgan-enhance',
            $sourceWidth,
            $sourceHeight
        );
    }

    /**
     * SwinIR（GPU VPS）で鮮明化し、結果を R2 へ新規保存する。元画像は残す。
     *
     * @return array{photo: array<string, mixed>, sourceWidth: ?int, sourceHeight: ?int, resultWidth: ?int, resultHeight: ?int}
     */
    public function enhanceWithSwinIr(int $userId, int $photoId): array
    {
        if (! $this->mediaConfig->swinirEnabled()) {
            throw new \InvalidArgumentException(__('SwinIR が有効ではありません。鮮明化設定を確認してください。'));
        }

        $source = Photo::query()->where('user_id', $userId)->find($photoId);
        if (! $source) {
            throw new \InvalidArgumentException(__('写真が見つかりません'));
        }
        if ($this->isVideoMime((string) $source->mime, pathinfo((string) $source->path, PATHINFO_EXTENSION))) {
            throw new \InvalidArgumentException(__('動画は AI 鮮明化の対象外です。'));
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(900);
        }

        $sourceWidth = $source->width ? (int) $source->width : null;
        $sourceHeight = $source->height ? (int) $source->height : null;

        $file = $this->readPhotoFile($source);
        $enhanced = app(SwinIrService::class)->enhanceImage(
            $file['contents'],
            $file['name'],
            $file['mime']
        );

        return $this->persistEnhancedPhoto(
            $userId,
            $photoId,
            $enhanced,
            'swinir-enhance',
            $sourceWidth,
            $sourceHeight
        );
    }

    /**
     * @param  array{binary: string, mime: string, extension: string, width?: ?int, height?: ?int}  $enhanced
     * @return array{photo: array<string, mixed>, sourceWidth: ?int, sourceHeight: ?int, resultWidth: ?int, resultHeight: ?int}
     */
    private function persistEnhancedPhoto(
        int $userId,
        int $photoId,
        array $enhanced,
        string $filenamePrefix,
        ?int $sourceWidth,
        ?int $sourceHeight
    ): array {
        app(EnhanceCancelService::class)->throwIfCancelled($userId, $photoId);

        $tmp = tempnam(sys_get_temp_dir(), 'enh_out_');
        if ($tmp === false) {
            throw new \RuntimeException(__('一時ファイルを作成できません。'));
        }

        try {
            file_put_contents($tmp, $enhanced['binary']);
            $uploaded = new UploadedFile(
                $tmp,
                $filenamePrefix.'.'.$enhanced['extension'],
                $enhanced['mime'],
                null,
                true
            );

            $photo = $this->saveEditedImage($userId, $photoId, $uploaded, __('AI鮮明化'));

            return [
                'photo' => $photo,
                'sourceWidth' => $sourceWidth,
                'sourceHeight' => $sourceHeight,
                'resultWidth' => isset($photo['width']) ? (int) $photo['width'] : ($enhanced['width'] ?? null),
                'resultHeight' => isset($photo['height']) ? (int) $photo['height'] : ($enhanced['height'] ?? null),
            ];
        } finally {
            @unlink($tmp);
        }
    }

    public function saveEditedImageFromUrl(int $userId, int $photoId, string $imageUrl, ?string $label = null): array
    {
        $imageUrl = trim($imageUrl);
        if ($imageUrl === '' || ! str_starts_with($imageUrl, 'https://')) {
            throw new \InvalidArgumentException(__('編集結果のURLが不正です。'));
        }

        // Cloudinary / 自前CDN以外は拒否
        $host = parse_url($imageUrl, PHP_URL_HOST) ?: '';
        if (! is_string($host) || (! str_ends_with($host, 'cloudinary.com') && ! str_ends_with($host, 'cloudinary.com.'))) {
            // allow res.cloudinary.com and subdomains
            if (! str_contains($host, 'cloudinary.com')) {
                throw new \InvalidArgumentException(__('許可されていない編集結果URLです。'));
            }
        }

        $response = \Illuminate\Support\Facades\Http::timeout(120)->get($imageUrl);
        if (! $response->successful()) {
            throw new \RuntimeException(__('編集結果の取得に失敗しました。'));
        }

        $binary = $response->body();
        if ($binary === '') {
            throw new \RuntimeException(__('編集結果が空です。'));
        }

        $tmp = tempnam(sys_get_temp_dir(), 'cld_edit_');
        if ($tmp === false) {
            throw new \RuntimeException(__('一時ファイルを作成できません。'));
        }

        try {
            file_put_contents($tmp, $binary);
            $uploaded = new UploadedFile($tmp, 'cloudinary-edit.jpg', 'image/jpeg', null, true);

            return $this->saveEditedImage($userId, $photoId, $uploaded, $label ?: __('Cloudinary編集'));
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Cloudinary Media Editor 用セッション開始。
     *
     * @return array{cloudName: string, publicId: string, resourceType: string}
     */
    public function startCloudinaryEdit(int $userId, int $photoId): array
    {
        if (! $this->mediaConfig->cloudinaryEditorEnabled()) {
            throw new \InvalidArgumentException(__('Cloudinary 編集が有効ではありません。'));
        }
        $photo = Photo::query()->where('user_id', $userId)->find($photoId);
        if (! $photo) {
            throw new \InvalidArgumentException(__('写真が見つかりません'));
        }

        return $this->cloudinary->startEditSession($photo);
    }

    /**
     * Media Editor の書き出し結果を R2 に保存し、一時アセットを削除する。
     *
     * @return array<string, mixed>
     */
    public function commitCloudinaryEdit(
        int $userId,
        int $photoId,
        string $exportUrl,
        string $tempPublicId,
        ?string $label = null
    ): array {
        $created = $this->saveEditedImageFromUrl($userId, $photoId, $exportUrl, $label);
        if ($tempPublicId !== '') {
            $this->cloudinary->deletePhoto($tempPublicId, 'image');
        }

        // 表示用の常設同期はしない（編集版も R2 のみ）
        return $created;
    }

    public function cancelCloudinaryEdit(string $tempPublicId): void
    {
        if ($tempPublicId !== '') {
            $this->cloudinary->deletePhoto($tempPublicId, 'image');
        }
    }

    public function trimVideo(int $userId, int $photoId, float $startSec, float $endSec): array
    {
        $source = Photo::query()->where('user_id', $userId)->find($photoId);
        if (! $source) {
            throw new \InvalidArgumentException(__('動画が見つかりません'));
        }
        if (! $this->isVideoMime((string) $source->mime, pathinfo((string) $source->path, PATHINFO_EXTENSION))) {
            throw new \InvalidArgumentException(__('動画以外はトリムできません。'));
        }

        // 切り出し後サイズは元より小さいことが多いが、新規レコードとして加算される
        $this->assertWithinFreeQuotaOrPaid($userId, max(0, (int) ($source->size_bytes ?? 0)));

        $disk = $this->storage();
        $tmpIn = tempnam(sys_get_temp_dir(), 'vid_in_');
        $tmpOut = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('vid_out_', true).'.mp4';
        if ($tmpIn === false) {
            throw new \RuntimeException(__('一時ファイルを作成できません。'));
        }

        try {
            file_put_contents($tmpIn, $this->storageForPhoto($source)->get($source->path));
            $this->ffmpeg->trim($tmpIn, $tmpOut, $startSec, $endSec);

            $basename = str_replace('.', '', uniqid('vid_trim_', true)).'.mp4';
            $dir = 'photos/'.$userId;
            $path = $disk->putFileAs($dir, new UploadedFile($tmpOut, $basename, 'video/mp4', null, true), $basename, [
                'visibility' => $this->objectVisibility(),
                'ContentType' => 'video/mp4',
            ]);
            if (! is_string($path) || $path === '') {
                throw new \RuntimeException(__('切り出し動画の保存に失敗しました。'));
            }

            $minSort = $this->allocateFrontPhotoSortOrder($userId);
            $photo = Photo::create([
                'user_id' => $userId,
                'album_id' => $source->album_id,
                'parent_photo_id' => $source->id,
                'path' => $path,
                'thumb_path' => $source->thumb_path,
                'original_name' => $source->original_name,
                'mime' => 'video/mp4',
                'size_bytes' => (int) filesize($tmpOut),
                'width' => $source->width,
                'height' => $source->height,
                'caption' => $source->caption,
                'edit_label' => sprintf('%s %.1f-%.1fs', __('トリム'), $startSec, $endSec),
                'taken_at' => $source->taken_at,
                'sort_order' => $minSort,
            ]);

            return $this->photoToArray($photo, $userId);
        } finally {
            @unlink($tmpIn);
            @unlink($tmpOut);
        }
    }

    /** @return array<string, mixed> */
    public function albumToArray(PhotoAlbum $album, ?int $viewerUserId = null, ?Photo $cover = null): array
    {
        if ($cover === null) {
            if ($album->cover_photo_id) {
                $cover = Photo::query()->find($album->cover_photo_id);
            }
            if (! $cover) {
                $cover = Photo::query()->where('album_id', $album->id)->orderByDesc('id')->first();
            }
        }

        $coverIsVideo = $cover
            ? $this->isVideoMime((string) $cover->mime, pathinfo((string) $cover->path, PATHINFO_EXTENSION))
            : false;
        $coverArr = $cover ? $this->photoToArray($cover, $viewerUserId) : null;

        $visibility = $album->visibilityEnum();
        $isOwner = $viewerUserId !== null && (int) $album->user_id === $viewerUserId;

        return [
            'id' => $album->id,
            'name' => $album->name,
            'description' => $album->description,
            'visibility' => $visibility->value,
            'visibilityLabel' => __($visibility->label()),
            'groupId' => $album->group_id,
            'groupName' => $album->relationLoaded('group') ? $album->group?->name : null,
            'ownerUserId' => $album->user_id,
            'ownerName' => $album->relationLoaded('user') ? $album->user?->display_name : null,
            'isOwner' => $isOwner,
            'canManage' => $isOwner,
            'hasPassword' => $album->hasPassword(),
            'isHidden' => (bool) $album->is_hidden,
            'isUnlocked' => $viewerUserId !== null && (
                ! $album->hasPassword() || $this->isAlbumUnlocked($viewerUserId, (int) $album->id)
            ),
            'coverPhotoId' => $album->cover_photo_id,
            'photoCount' => (int) ($album->active_photos_count ?? $album->activePhotos()->count()),
            'coverUrl' => $coverArr
                ? ($coverArr['thumbUrl'] ?? $coverArr['url'] ?? null)
                : null,
            'coverMediaKind' => $coverIsVideo ? 'video' : 'image',
            'sortOrder' => (int) $album->sort_order,
        ];
    }

    /** @return array<string, mixed> */
    public function photoToArray(Photo $photo, ?int $viewerUserId = null): array
    {
        $takenAt = $photo->taken_at?->format('Y-m-d H:i');
        $takenDate = $photo->taken_at?->format('Y-m-d');
        $takenAtLocal = $photo->taken_at?->format('Y-m-d\TH:i');
        $mime = (string) ($photo->mime ?? '');
        $mediaKind = $this->isVideoMime($mime, pathinfo((string) $photo->path, PATHINFO_EXTENSION))
            ? 'video'
            : 'image';

        $fileUrl = '/photos/'.$photo->id.'/file';
        $thumbFileUrl = '/photos/'.$photo->id.'/file?variant=thumb';
        $useAppProxy = $this->shouldProxyPhotoDelivery($photo);

        if ($useAppProxy) {
            $url = $fileUrl;
            $thumbUrl = $thumbFileUrl;
        } else {
            $storageUrl = $this->publicUrlForPhoto($photo, $photo->path);
            $storageThumb = $this->publicUrlForPhoto($photo, $photo->thumb_path ?: $photo->path) ?: asset('icons/pwa-192.png');
            $url = $storageUrl ?: $fileUrl;
            $thumbUrl = $storageThumb;
        }

        if ($this->mediaConfig->pipelineUsesCloudinaryDisplay()
            && is_string($photo->cloudinary_public_id)
            && $photo->cloudinary_public_id !== '') {
            $resourceType = ($photo->cloudinary_resource_type === 'video' || $mediaKind === 'video')
                ? 'video'
                : 'image';
            $thumbEdge = max(240, (int) config('photos.thumb_long_edge', 720));

            if ($resourceType === 'video') {
                $cdn = $this->cloudinary->deliveryUrl($photo->cloudinary_public_id, null, 'video');
                $cdnThumb = $this->cloudinary->deliveryUrl($photo->cloudinary_public_id, $thumbEdge, 'video', true);
            } else {
                $cdn = $this->cloudinary->deliveryUrl($photo->cloudinary_public_id, null, 'image');
                $cdnThumb = $this->cloudinary->deliveryUrl($photo->cloudinary_public_id, $thumbEdge, 'image');
            }
            if ($cdn) {
                $url = $cdn;
            }
            if ($cdnThumb) {
                $thumbUrl = $cdnThumb;
            }
        }

        return [
            'id' => $photo->id,
            'albumId' => $photo->album_id,
            'parentPhotoId' => $photo->parent_photo_id,
            'editLabel' => $photo->edit_label,
            'url' => $url,
            'thumbUrl' => $thumbUrl,
            'originalName' => $photo->original_name,
            'caption' => $photo->caption,
            'mime' => $mime,
            'mediaKind' => $mediaKind,
            'width' => $photo->width,
            'height' => $photo->height,
            'sizeBytes' => (int) ($photo->size_bytes ?? 0),
            'takenAt' => $takenAt,
            'takenDate' => $takenDate,
            'takenAtLocal' => $takenAtLocal,
            'archived' => $photo->archived_at !== null,
            'archivedAt' => $photo->archived_at?->toIso8601String(),
            'createdAt' => $photo->created_at?->toIso8601String(),
            'canEdit' => $viewerUserId !== null && (int) $photo->user_id === $viewerUserId,
            'fileUrl' => $fileUrl,
            'storageTier' => $photo->storage_tier ?? 'hot',
        ];
    }

    private function shouldProxyPhotoDelivery(Photo $photo): bool
    {
        $disk = $this->diskForPhoto($photo);
        if (in_array($disk, ['r2', 'backblaze'], true)) {
            return true;
        }

        return (string) config('filesystems.disks.'.$disk.'.driver', 'local') === 's3';
    }

    private function formatDateGroupLabel(string $date, string $today, string $yesterday): string
    {
        if ($date === 'unknown') {
            return __('日付不明');
        }
        if ($date === $today) {
            return __('今日');
        }
        if ($date === $yesterday) {
            return __('昨日');
        }

        try {
            $carbon = \Carbon\Carbon::createFromFormat('Y-m-d', $date, config('app.timezone', 'Asia/Tokyo'));

            return app()->getLocale() === 'en'
                ? $carbon->locale('en')->isoFormat('MMM D, YYYY')
                : $carbon->format('Y年n月j日');
        } catch (\Throwable) {
            return $date;
        }
    }

    private function maybeSyncCloudinary(Photo $photo): void
    {
        if (! $this->mediaConfig->pipelineUsesCloudinaryDisplay() || ! $this->cloudinary->isReady()) {
            return;
        }

        try {
            // レスポンス返却後に実行（同期キューでもアップロード待ちを伸ばさない）
            SyncPhotoToCloudinary::dispatch($photo->id)->afterResponse();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function diskForPhoto(Photo $photo): string
    {
        if (in_array(($photo->storage_tier ?? 'hot'), ['cold', 'overflow'], true)
            && is_string($photo->cold_disk)
            && $photo->cold_disk !== '') {
            return $photo->cold_disk;
        }

        return $this->diskName();
    }

    private function storageForPhoto(Photo $photo): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk($this->diskForPhoto($photo));
    }

    private function publicUrlForPhoto(Photo $photo, ?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        // サムネはホット側に残す運用のため、thumb は常に主ディスクを優先
        if ($path === $photo->thumb_path && $path !== $photo->path) {
            try {
                return $this->storage()->url($path);
            } catch (\Throwable) {
                // fall through
            }
        }

        try {
            return $this->storageForPhoto($photo)->url($path);
        } catch (\Throwable) {
            return $this->storage()->url($path);
        }
    }

    private function publicUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return $this->storage()->url($path);
    }
}
