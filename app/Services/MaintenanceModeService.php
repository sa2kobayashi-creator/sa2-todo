<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * アプリ全体のメンテナンス停止。DB に依存せずファイルで持つ（共有ホスト向け）。
 */
class MaintenanceModeService
{
    public const FILENAME = 'sa2-maintenance.json';

    public function path(): string
    {
        return storage_path('framework/'.self::FILENAME);
    }

    public function isEnabled(): bool
    {
        $state = $this->read();

        return ! empty($state['enabled']);
    }

    public function message(): string
    {
        $state = $this->read();
        $message = trim((string) ($state['message'] ?? ''));

        return $message !== ''
            ? $message
            : __('ただいまメンテナンス中です。しばらくしてから再度アクセスしてください。');
    }

    /** @return array{enabled: bool, message: string, enabled_at: ?string} */
    public function formState(): array
    {
        $state = $this->read();

        return [
            'enabled' => ! empty($state['enabled']),
            'message' => (string) ($state['message'] ?? ''),
            'enabled_at' => isset($state['enabled_at']) && is_string($state['enabled_at'])
                ? $state['enabled_at']
                : null,
        ];
    }

    public function enable(?string $message = null, ?int $userId = null): void
    {
        $this->write([
            'enabled' => true,
            'message' => trim((string) $message),
            'enabled_at' => now()->toIso8601String(),
            'enabled_by' => $userId,
        ]);
    }

    public function disable(): void
    {
        $path = $this->path();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function isExemptPath(string $path): bool
    {
        $path = '/'.ltrim($path, '/');
        $exact = [
            '/login',
            '/logout',
            '/locale',
            '/csrf-token',
            '/terms',
            '/privacy',
            '/tokushoho',
            '/up',
        ];
        if (in_array($path, $exact, true)) {
            return true;
        }

        foreach (['/password/', '/webhooks/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function allowsRequest(Request $request): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }

        if ($request->user()?->isSuperAdmin()) {
            return true;
        }

        return $this->isExemptPath($request->path());
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $state */
    private function write(array $state): void
    {
        $dir = dirname($this->path());
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $tmp = $this->path().'.tmp';
        file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        rename($tmp, $this->path());
    }
}
