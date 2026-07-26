<?php

namespace App\Services;

use App\Exceptions\EnhanceCancelledException;
use Illuminate\Support\Facades\Cache;

class EnhanceCancelService
{
    private ?int $userId = null;

    private ?int $photoId = null;

    public function begin(int $userId, int $photoId): void
    {
        $this->userId = $userId;
        $this->photoId = $photoId;
        Cache::forget($this->keyFor($userId, $photoId));

        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(false);
        }
    }

    public function clear(?int $userId = null, ?int $photoId = null): void
    {
        $userId ??= $this->userId;
        $photoId ??= $this->photoId;
        if ($userId !== null && $photoId !== null) {
            Cache::forget($this->keyFor($userId, $photoId));
        }

        $this->userId = null;
        $this->photoId = null;
    }

    public function requestCancel(int $userId, int $photoId): void
    {
        Cache::put($this->keyFor($userId, $photoId), 1, now()->addMinutes(30));
    }

    public function isCancelled(?int $userId = null, ?int $photoId = null): bool
    {
        $userId ??= $this->userId;
        $photoId ??= $this->photoId;
        if ($userId === null || $photoId === null) {
            return false;
        }

        if (connection_aborted()) {
            return true;
        }

        return (bool) Cache::get($this->keyFor($userId, $photoId));
    }

    public function throwIfCancelled(?int $userId = null, ?int $photoId = null): void
    {
        if ($this->isCancelled($userId, $photoId)) {
            throw new EnhanceCancelledException(__('鮮明化を中止しました。'));
        }
    }

    private function keyFor(int $userId, int $photoId): string
    {
        return 'photo_enhance_cancel:'.$userId.':'.$photoId;
    }
}
