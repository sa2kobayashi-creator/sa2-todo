<?php

namespace App\Services;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\VideoGrant;
use App\Models\User;
use Illuminate\Support\Str;

class LiveKitCallService
{
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

    private function directRoomName(int $groupId, int $firstUserId, int $secondUserId): string
    {
        $userIds = [$firstUserId, $secondUserId];
        sort($userIds, SORT_NUMERIC);

        return 'sa2-dm-'.$groupId.'-'.$userIds[0].'-'.$userIds[1];
    }
}
