<?php

namespace App\Services;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\VideoGrant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class LiveKitCallService
{
    private const INVITE_TTL_SECONDS = 90;

    public function __construct(private LiveKitConfigService $config) {}

    public function isConfigured(): bool
    {
        return $this->config->isReady();
    }

    /**
     * @return array{serverUrl: string, participantToken: string, roomName: string}
     */
    public function tokenForDirectMessage(User $user, int $groupId, int $peerUserId): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException(__('通話機能はまだ設定されていません。'));
        }

        $roomName = $this->directRoomName($groupId, (int) $user->id, $peerUserId);
        $options = (new AccessTokenOptions())
            ->setIdentity('user-'.(int) $user->id)
            ->setName(Str::limit((string) $user->display_name, 100, ''))
            ->setTtl(900);
        $grant = (new VideoGrant())
            ->setRoomJoin()
            ->setRoomName($roomName)
            ->setCanPublish()
            ->setCanSubscribe();

        $participantToken = (new AccessToken(
            $this->config->apiKey(),
            $this->config->apiSecret(),
        ))
            ->init($options)
            ->setGrant($grant)
            ->toJwt();

        return [
            'serverUrl' => $this->config->url(),
            'participantToken' => $participantToken,
            'roomName' => $roomName,
        ];
    }

    /**
     * @return array{groupId: int, fromUserId: int, fromName: string, toUserId: int, roomName: string, startedAt: string}
     */
    public function ringDirectCall(User $fromUser, int $groupId, int $peerUserId): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException(__('通話機能はまだ設定されていません。'));
        }

        $fromId = (int) $fromUser->id;
        $roomName = $this->directRoomName($groupId, $fromId, $peerUserId);
        $payload = [
            'groupId' => $groupId,
            'fromUserId' => $fromId,
            'fromName' => (string) $fromUser->display_name,
            'toUserId' => $peerUserId,
            'roomName' => $roomName,
            'startedAt' => now()->toIso8601String(),
        ];

        Cache::put($this->inviteKey($roomName), $payload, now()->addSeconds(self::INVITE_TTL_SECONDS));
        Cache::put($this->incomingKey($peerUserId), $payload, now()->addSeconds(self::INVITE_TTL_SECONDS));

        return $payload;
    }

    public function cancelDirectCall(int $userId, int $groupId, int $peerUserId): void
    {
        $roomName = $this->directRoomName($groupId, $userId, $peerUserId);
        /** @var array<string, mixed>|null $invite */
        $invite = Cache::get($this->inviteKey($roomName));
        if (! is_array($invite)) {
            $this->clearIncoming($peerUserId);
            $this->clearIncoming($userId);

            return;
        }

        $fromId = (int) ($invite['fromUserId'] ?? 0);
        $toId = (int) ($invite['toUserId'] ?? 0);
        if ($userId !== $fromId && $userId !== $toId) {
            throw new \InvalidArgumentException(__('この通話を操作する権限がありません。'));
        }

        Cache::forget($this->inviteKey($roomName));
        $this->clearIncoming($fromId);
        $this->clearIncoming($toId);
    }

    public function declineIncomingCall(int $userId): void
    {
        $incoming = $this->incomingForUser($userId);
        if ($incoming === null) {
            return;
        }

        $groupId = (int) ($incoming['groupId'] ?? 0);
        $fromId = (int) ($incoming['fromUserId'] ?? 0);
        $toId = (int) ($incoming['toUserId'] ?? 0);
        if ($fromId > 0) {
            Cache::put($this->declinedKey($fromId), [
                'byUserId' => $userId,
                'groupId' => $groupId,
            ], now()->addSeconds(30));
        }
        if ($groupId > 0 && $fromId > 0 && $toId > 0) {
            $this->cancelDirectCall($userId, $groupId, $fromId === $userId ? $toId : $fromId);
        } else {
            $this->clearIncoming($userId);
        }
    }

    /** @return array{byUserId: int, groupId: int}|null */
    public function consumeDeclinedNotice(int $userId): ?array
    {
        $key = $this->declinedKey($userId);
        /** @var array<string, mixed>|null $payload */
        $payload = Cache::pull($key);
        if (! is_array($payload)) {
            return null;
        }

        return [
            'byUserId' => (int) ($payload['byUserId'] ?? 0),
            'groupId' => (int) ($payload['groupId'] ?? 0),
        ];
    }

    /** @return array{groupId: int, fromUserId: int, fromName: string, toUserId: int, roomName: string, startedAt: string, href: string}|null */
    public function incomingForUser(int $userId): ?array
    {
        /** @var array<string, mixed>|null $payload */
        $payload = Cache::get($this->incomingKey($userId));
        if (! is_array($payload)) {
            return null;
        }

        if ((int) ($payload['toUserId'] ?? 0) !== $userId) {
            return null;
        }

        $groupId = (int) ($payload['groupId'] ?? 0);
        $fromId = (int) ($payload['fromUserId'] ?? 0);
        if ($groupId <= 0 || $fromId <= 0) {
            return null;
        }

        return [
            'groupId' => $groupId,
            'fromUserId' => $fromId,
            'fromName' => (string) ($payload['fromName'] ?? ''),
            'toUserId' => $userId,
            'roomName' => (string) ($payload['roomName'] ?? ''),
            'startedAt' => (string) ($payload['startedAt'] ?? ''),
            'href' => '/messages/'.$groupId.'/dm/'.$fromId,
        ];
    }

    public function clearIncoming(int $userId): void
    {
        Cache::forget($this->incomingKey($userId));
    }

    public function directRoomName(int $groupId, int $firstUserId, int $secondUserId): string
    {
        $userIds = [$firstUserId, $secondUserId];
        sort($userIds, SORT_NUMERIC);

        return 'sa2-dm-'.$groupId.'-'.$userIds[0].'-'.$userIds[1];
    }

    private function inviteKey(string $roomName): string
    {
        return 'msg-call-invite:'.$roomName;
    }

    private function incomingKey(int $userId): string
    {
        return 'msg-call-incoming:'.$userId;
    }

    private function declinedKey(int $userId): string
    {
        return 'msg-call-declined:'.$userId;
    }
}
