<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\UserAccountDeletionService;

/**
 * HTTP 応答後にアカウント削除を実行する（共有ホストでも queue worker 不要）。
 */
class DeleteUserAccountJob
{
    public function __construct(public int $userId) {}

    public function handle(UserAccountDeletionService $deletion): void
    {
        $user = User::query()->find($this->userId);
        if (! $user) {
            return;
        }

        $deletion->delete($user);
    }

    public static function dispatchAfterHttp(int $userId): void
    {
        dispatch(function () use ($userId) {
            app()->call([new self($userId), 'handle']);
        })->afterResponse();
    }
}
