<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaStorageSetting extends Model
{
    public const PROVIDER_R2 = 'r2';

    public const PROVIDER_CLOUDINARY = 'cloudinary';

    public const PROVIDER_BACKBLAZE = 'backblaze';

    public const PROVIDER_PIPELINE = 'pipeline';

    protected $fillable = [
        'provider',
        'enabled',
        'settings',
        'secrets',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'settings' => 'array',
            'secrets' => 'encrypted:array',
            'last_tested_at' => 'datetime',
        ];
    }

    public static function forProvider(string $provider): self
    {
        return static::query()->firstOrCreate(
            ['provider' => $provider],
            ['enabled' => false, 'settings' => [], 'secrets' => []]
        );
    }

    /** @return array<string, mixed> */
    public function settingsArray(): array
    {
        return is_array($this->settings) ? $this->settings : [];
    }

    /** @return array<string, mixed> */
    public function secretsArray(): array
    {
        return is_array($this->secrets) ? $this->secrets : [];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settingsArray(), $key, $default);
    }

    public function secret(string $key, mixed $default = null): mixed
    {
        return data_get($this->secretsArray(), $key, $default);
    }

    public function hasSecret(string $key): bool
    {
        $value = $this->secret($key);

        return is_string($value) && $value !== '';
    }

    public function maskedSecret(string $key): string
    {
        if (! $this->hasSecret($key)) {
            return '';
        }

        return '••••••••';
    }
}
