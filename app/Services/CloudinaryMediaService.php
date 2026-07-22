<?php

namespace App\Services;

use App\Models\MediaStorageSetting;
use App\Models\Photo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CloudinaryMediaService
{
    public function __construct(private MediaStorageConfigService $config) {}

    public function isReady(): bool
    {
        return $this->config->cloudinaryEnabled();
    }

    public function cloudName(): string
    {
        return (string) $this->config->get(MediaStorageSetting::PROVIDER_CLOUDINARY)->setting('cloud_name', '');
    }

    /**
     * 原本を Cloudinary に同期し、public_id を保存する（表示用・オプトイン時のみ）。
     */
    public function syncPhoto(Photo $photo): bool
    {
        if (! $this->isReady()) {
            return false;
        }

        $folder = trim((string) $this->config->get(MediaStorageSetting::PROVIDER_CLOUDINARY)->setting('folder', 'sa2todo'), '/');
        $mime = (string) ($photo->mime ?? '');
        $isVideo = str_starts_with($mime, 'video/');
        $publicId = $folder !== ''
            ? $folder.'/photo_'.$photo->id
            : 'photo_'.$photo->id;

        $uploaded = $this->uploadPhotoBinary($photo, $publicId, $isVideo ? 'video' : 'image');
        if ($uploaded === null) {
            return false;
        }

        $photo->cloudinary_public_id = $uploaded['public_id'];
        $photo->cloudinary_resource_type = $uploaded['resource_type'];
        $photo->save();

        return true;
    }

    /**
     * Media Editor 用の一時アセットを作成する（永続同期しない）。
     *
     * @return array{cloudName: string, publicId: string, resourceType: string}
     */
    public function startEditSession(Photo $photo): array
    {
        if (! $this->isReady()) {
            throw new \InvalidArgumentException(__('Cloudinary が設定されていません。'));
        }

        $mime = (string) ($photo->mime ?? '');
        if (str_starts_with($mime, 'video/')) {
            throw new \InvalidArgumentException(__('動画は Cloudinary 編集の対象外です。動画トリムを使ってください。'));
        }

        $folder = trim((string) $this->config->get(MediaStorageSetting::PROVIDER_CLOUDINARY)->setting('folder', 'sa2todo'), '/');
        $publicId = ($folder !== '' ? $folder.'/' : '').'edit_tmp/photo_'.$photo->id.'_'.Str::lower(Str::random(8));

        $uploaded = $this->uploadPhotoBinary($photo, $publicId, 'image');
        if ($uploaded === null) {
            throw new \RuntimeException(__('Cloudinary への一時アップロードに失敗しました。'));
        }

        return [
            'cloudName' => $this->cloudName(),
            'publicId' => $uploaded['public_id'],
            'resourceType' => $uploaded['resource_type'],
        ];
    }

    /**
     * @return array{public_id: string, resource_type: string}|null
     */
    private function uploadPhotoBinary(Photo $photo, string $publicId, string $resourceType): ?array
    {
        $resolved = $this->resolveSource($photo);
        if ($resolved === null) {
            return null;
        }
        [$sourceDisk, $path] = $resolved;

        $row = $this->config->get(MediaStorageSetting::PROVIDER_CLOUDINARY);
        $cloud = (string) $row->setting('cloud_name', '');
        $apiKey = (string) $row->secret('api_key', '');
        $apiSecret = (string) $row->secret('api_secret', '');
        $endpoint = "https://api.cloudinary.com/v1_1/{$cloud}/{$resourceType}/upload";
        $timeout = $resourceType === 'video' ? 600 : 180;

        $remoteUrl = null;
        try {
            $candidate = Storage::disk($sourceDisk)->url($path);
            if (is_string($candidate) && str_starts_with($candidate, 'http')) {
                $remoteUrl = $candidate;
            }
        } catch (\Throwable) {
            $remoteUrl = null;
        }

        if ($remoteUrl) {
            $response = Http::withBasicAuth($apiKey, $apiSecret)
                ->timeout($timeout)
                ->asForm()
                ->post($endpoint, [
                    'file' => $remoteUrl,
                    'public_id' => $publicId,
                    'overwrite' => 'true',
                    'invalidate' => 'true',
                ]);
        } else {
            $binary = Storage::disk($sourceDisk)->get($path);
            if (! is_string($binary) || $binary === '') {
                return null;
            }
            $filename = basename($path) ?: ('photo_'.$photo->id.($resourceType === 'video' ? '.mp4' : '.jpg'));
            $response = Http::withBasicAuth($apiKey, $apiSecret)
                ->timeout($timeout)
                ->attach('file', $binary, $filename)
                ->post($endpoint, [
                    'public_id' => $publicId,
                    'overwrite' => 'true',
                    'invalidate' => 'true',
                ]);
        }

        if (! $response->successful()) {
            report(new \RuntimeException('Cloudinary upload failed: '.$response->body()));

            return null;
        }

        return [
            'public_id' => (string) ($response->json('public_id') ?: $publicId),
            'resource_type' => (string) ($response->json('resource_type') ?: $resourceType),
        ];
    }

    /** @return array{0: string, 1: string}|null */
    private function resolveSource(Photo $photo): ?array
    {
        $disk = (string) config('photos.disk', 'public');
        $path = (string) $photo->path;
        if ($path !== '' && Storage::disk($disk)->exists($path)) {
            return [$disk, $path];
        }
        if (($photo->storage_tier ?? 'hot') === 'cold'
            && is_string($photo->cold_disk)
            && $photo->cold_disk !== '') {
            $coldPath = (string) ($photo->cold_path ?: $path);
            if ($coldPath !== '' && Storage::disk($photo->cold_disk)->exists($coldPath)) {
                return [$photo->cold_disk, $coldPath];
            }
        }

        return null;
    }

    public function deletePhoto(?string $publicId, ?string $resourceType = null): void
    {
        if (! $publicId || ! $this->isReady()) {
            return;
        }

        $row = $this->config->get(MediaStorageSetting::PROVIDER_CLOUDINARY);
        $cloud = (string) $row->setting('cloud_name', '');
        $apiKey = (string) $row->secret('api_key', '');
        $apiSecret = (string) $row->secret('api_secret', '');
        if ($cloud === '' || $apiKey === '' || $apiSecret === '') {
            return;
        }

        $type = in_array($resourceType, ['image', 'video', 'raw'], true) ? $resourceType : 'image';
        $timestamp = time();
        $toSign = 'public_id='.$publicId.'&timestamp='.$timestamp.$apiSecret;
        $signature = sha1($toSign);

        try {
            Http::asForm()
                ->timeout(30)
                ->post("https://api.cloudinary.com/v1_1/{$cloud}/{$type}/destroy", [
                    'public_id' => $publicId,
                    'timestamp' => $timestamp,
                    'api_key' => $apiKey,
                    'signature' => $signature,
                ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function deliveryUrl(?string $publicId, ?int $width = null, string $resourceType = 'image', bool $asPoster = false): ?string
    {
        if (! $publicId || ! $this->isReady()) {
            return null;
        }

        $cloud = $this->cloudName();
        if ($cloud === '') {
            return null;
        }

        $type = $resourceType === 'video' ? 'video' : 'image';

        if ($type === 'video' && $asPoster) {
            $transform = 'so_0,q_auto,f_jpg';
            if ($width !== null && $width > 0) {
                $transform = 'c_limit,w_'.$width.','.$transform;
            }

            return 'https://res.cloudinary.com/'.$cloud.'/video/upload/'.$transform.'/'.$publicId.'.jpg';
        }

        if ($type === 'video') {
            $transform = 'q_auto';
            if ($width !== null && $width > 0) {
                $transform = 'c_limit,w_'.$width.','.$transform;
            }

            return 'https://res.cloudinary.com/'.$cloud.'/video/upload/'.$transform.'/'.$publicId;
        }

        $transform = 'q_auto,f_auto';
        if ($width !== null && $width > 0) {
            $transform = 'c_limit,w_'.$width.','.$transform;
        }

        return 'https://res.cloudinary.com/'.$cloud.'/image/upload/'.$transform.'/'.$publicId;
    }
}
