<?php

namespace App\Support;

use App\Models\User;

final class TenantContext
{
    private ?int $tenantId = null;

    public function set(?int $tenantId): void
    {
        $this->tenantId = $tenantId !== null && $tenantId > 0 ? $tenantId : null;
    }

    public function fromUser(?User $user): void
    {
        $this->set($user?->tenant_id);
    }

    public function id(): ?int
    {
        return $this->tenantId;
    }

    public function isPlatform(): bool
    {
        return $this->tenantId === null;
    }

    public function scope(): int
    {
        return $this->tenantId ?? 0;
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(?int $tenantId, callable $callback): mixed
    {
        $previous = $this->tenantId;
        $this->set($tenantId);
        try {
            return $callback();
        } finally {
            $this->tenantId = $previous;
        }
    }

    public static function current(): self
    {
        return app(self::class);
    }

    public static function idOrNull(): ?int
    {
        if (! app()->bound(self::class)) {
            return null;
        }

        return app(self::class)->id();
    }

    public static function scopeOrZero(): int
    {
        if (! app()->bound(self::class)) {
            return 0;
        }

        return app(self::class)->scope();
    }
}
