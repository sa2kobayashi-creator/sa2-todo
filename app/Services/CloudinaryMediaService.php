<?php

namespace App\Services;

use App\Models\MediaStorageSetting;
use App\Models\Photo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class CloudinaryMediaService
{
    public function __construct(private MediaStorageConfigService $config) {}

    public function isReady(): bool
    {
        return $this->config->cloudinaryEnabled();
    }

    /**
     * 原本を Cloudinary に同期し、public_id を保存する。
     */
    public function syncPhoto(Photo $photo): bool
    {
        if (! $this->isReady()) {
            return false;
        }

        $mime = (string) ($photo->mime ?? '');
        if (str_starts_with($mime, 'video/')) {
            // 動画は料金・負荷が大きいため第1段では対象外
            return false;
        }

        $disk = (string) config('photos.disk', 'public');
        $path = (string) $photo->path;
        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            return false;
        }

        $row = $this->config->get(MediaStorageSetting::PROVIDER_CLOUDINARY);
        $cloud = (string) $row->setting('cloud_name', '');
        $apiKey = (string) $row->secret('api_key', '');
        $apiSecret = (string) $row->secret('api_secret', '');
        $folder = trim((string) $row->setting('folder', 'sa2todo'), '/');

        $publicId = $folder !== ''
            ? $folder.'/photo_'.$photo->id
            : 'photo_'.$photo->id;

        $binary = Storage::disk($disk)->get($path);
        if (! is_string($binary) || $binary === '') {
            return false;
        }

        $filename = basename($path) ?: ('photo_'.$photo->id.'.jpg');
        $response = Http::withBasicAuth($apiKey, $apiSecret)
            ->timeout(120)
            ->attach('file', $binary, $filename)
            ->post("https://api.cloudinary.com/v1_1/{$cloud}/image/upload", [
                'public_id' => $publicId,
                'overwrite' => 'true',
                'invalidate' => 'true',
            ]);

        if (! $response->successful()) {
            report(new \RuntimeException('Cloudinary upload failed: '.$response->body()));

            return false;
        }

        $returnedId = (string) ($response->json('public_id') ?: $publicId);
        $photo->cloudinary_public_id = $returnedId;
        $photo->save();

        return true;
    }

    public function deletePhoto(?string $publicId): void
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

        $timestamp = time();
        $toSign = 'public_id='.$publicId.'&timestamp='.$timestamp.$apiSecret;
        $signature = sha1($toSign);

        try {
            Http::asForm()
                ->timeout(30)
                ->post("https://api.cloudinary.com/v1_1/{$cloud}/image/destroy", [
                    'public_id' => $publicId,
                    'timestamp' => $timestamp,
                    'api_key' => $apiKey,
                    'signature' => $signature,
                ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function deliveryUrl(?string $publicId, ?int $width = null): ?string
    {
        if (! $publicId || ! $this->isReady()) {
            return null;
        }

        $cloud = (string) $this->config->get(MediaStorageSetting::PROVIDER_CLOUDINARY)->setting('cloud_name', '');
        if ($cloud === '') {
            return null;
        }

        $transform = 'q_auto,f_auto';
        if ($width !== null && $width > 0) {
            $transform = 'c_limit,w_'.$width.','.$transform;
        }

        return 'https://res.cloudinary.com/'.$cloud.'/image/upload/'.$transform.'/'.$publicId;
    }
}
