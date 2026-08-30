<?php

namespace App\Services;

use App\Models\MediaStorageSetting;
use App\Support\AppInstall;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/**
 * 運営者向け Android APK 配布設定（公開販売画面）。
 */
class AppInstallConfigService
{
    public function configRow(): MediaStorageSetting
    {
        return MediaStorageSetting::forUse(MediaStorageSetting::PROVIDER_APP_INSTALL);
    }

    public function applyRuntime(): void
    {
        $row = $this->configRow();
        if (! $row->exists) {
            return;
        }

        $url = trim((string) $row->setting('apk_url', ''));
        if ($url !== '') {
            config(['app_install.android_apk_url' => $url]);
        }
    }

    /** @return array<string, mixed> */
    public function formState(): array
    {
        $this->applyRuntime();
        $row = $this->configRow();
        $relative = AppInstall::localRelativePath();
        $absolute = public_path($relative);
        $hasFile = is_file($absolute);

        return [
            'apk_url' => trim((string) $row->setting('apk_url', config('app_install.android_apk_url', ''))),
            'has_local_file' => $hasFile,
            'local_path' => $relative,
            'local_size_label' => $hasFile ? $this->formatBytes((int) filesize($absolute)) : null,
            'effective_url' => AppInstall::androidApkUrl(),
            'available' => AppInstall::androidApkAvailable(),
        ];
    }

    public function saveUrl(?string $url): void
    {
        $url = trim((string) $url);
        if ($url !== '' && preg_match('#^https?://#i', $url) !== 1) {
            throw new \InvalidArgumentException(__('APKのURLは http:// または https:// で始めてください。'));
        }

        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_APP_INSTALL);
        $settings = $row->settingsArray();
        $settings['apk_url'] = $url;
        $row->fill([
            'enabled' => $url !== '' || AppInstall::localFileExists(),
            'settings' => $settings,
        ]);
        $row->save();
        $this->applyRuntime();
    }

    public function storeUploadedApk(UploadedFile $file): string
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if ($ext !== 'apk') {
            throw new \InvalidArgumentException(__('APKファイル（.apk）を選んでください。'));
        }

        $relative = AppInstall::localRelativePath();
        $dir = dirname(public_path($relative));
        File::ensureDirectoryExists($dir);
        $file->move($dir, basename($relative));

        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_APP_INSTALL);
        $settings = $row->settingsArray();
        $settings['uploaded_at'] = now()->toIso8601String();
        $row->fill([
            'enabled' => true,
            'settings' => $settings,
        ]);
        $row->save();
        $this->applyRuntime();

        return $relative;
    }

    public function removeLocalApk(): bool
    {
        $absolute = public_path(AppInstall::localRelativePath());
        $removed = false;
        if (is_file($absolute)) {
            $removed = @unlink($absolute);
        }

        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_APP_INSTALL);
        $settings = $row->settingsArray();
        unset($settings['uploaded_at']);
        $url = trim((string) ($settings['apk_url'] ?? ''));
        $row->fill([
            'enabled' => $url !== '',
            'settings' => $settings,
        ]);
        $row->save();
        $this->applyRuntime();

        return $removed;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
