<?php

namespace App\Services;

use App\Models\User;

/**
 * 経路検索の結果を、所属グループのチャット（全体 or 個別）へ送る。
 */
class TransitShareService
{
    public function __construct(
        private GroupService $groups,
        private GroupChatService $chat,
        private TransitService $transit,
    ) {}

    /**
     * @return list<array{id: int, name: string, members: list<array{userId: int, displayName: string}>}>
     */
    public function targetsFor(int $userId): array
    {
        $out = [];
        foreach ($this->groups->listApprovedForUser($userId) as $group) {
            $groupId = (int) ($group['id'] ?? 0);
            if ($groupId <= 0) {
                continue;
            }
            $members = [];
            foreach ($this->groups->listMembers($groupId) as $member) {
                $peerId = (int) ($member['userId'] ?? 0);
                if ($peerId <= 0 || $peerId === $userId) {
                    continue;
                }
                $members[] = [
                    'userId' => $peerId,
                    'displayName' => trim((string) ($member['displayName'] ?? '')) ?: __('不明'),
                ];
            }
            $out[] = [
                'id' => $groupId,
                'name' => (string) ($group['name'] ?? ''),
                'members' => $members,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, href: string, id: int}
     */
    public function share(User $user, int $groupId, ?int $peerUserId, array $payload): array
    {
        $body = $this->formatMessage($payload);
        $message = $this->chat->send((int) $user->id, $groupId, $body, [], $peerUserId);
        $id = (int) ($message['id'] ?? 0);
        $href = $peerUserId
            ? '/messages/'.$groupId.'/dm/'.$peerUserId.'#msg-'.$id
            : '/messages/'.$groupId.'#msg-'.$id;

        return ['ok' => true, 'href' => $href, 'id' => $id];
    }

    /** @param  array<string, mixed>  $payload */
    public function formatMessage(array $payload): string
    {
        $from = trim((string) ($payload['from'] ?? ''));
        $to = trim((string) ($payload['to'] ?? ''));
        $itinerary = is_array($payload['itinerary'] ?? null) ? $payload['itinerary'] : [];
        $note = trim((string) ($payload['note'] ?? ''));

        $lines = [
            '【'.__('経路').'】'.$from.' → '.$to,
        ];
        $summary = trim((string) ($itinerary['summary'] ?? ''));
        if ($summary !== '') {
            $lines[] = $summary;
        }
        $dep = trim((string) ($itinerary['departureTime'] ?? ''));
        $arr = trim((string) ($itinerary['arrivalTime'] ?? ''));
        $rest = [];
        if ($dep !== '' && $arr !== '') {
            $rest[] = $dep.' → '.$arr;
        } elseif ($dep !== '' || $arr !== '') {
            $rest[] = $dep.$arr;
        }
        foreach (['durationLabel', 'waitLabel', 'fareLabel'] as $key) {
            $value = trim((string) ($itinerary[$key] ?? ''));
            if ($value !== '') {
                $rest[] = $value;
            }
        }
        if (array_key_exists('transfers', $itinerary) && $itinerary['transfers'] !== null && $itinerary['transfers'] !== '') {
            $rest[] = __('乗換').' '.(int) $itinerary['transfers'].__('回');
        }
        if ($rest !== []) {
            $lines[] = implode(' ・ ', $rest);
        }
        foreach (array_slice($itinerary['legs'] ?? [], 0, 8) as $leg) {
            if (! is_array($leg)) {
                continue;
            }
            $lines[] = $this->formatLeg($leg);
        }
        if ($from !== '' && $to !== '') {
            $lines[] = 'Google: '.$this->transit->buildGoogleMapsTransitUrl($from, $to);
            $lines[] = 'Yahoo: '.$this->transit->buildYahooTransitUrl($from, $to);
        }
        if ($note !== '') {
            $lines[] = $note;
        }

        return mb_substr(implode("\n", array_filter($lines, fn ($line) => $line !== '')), 0, 5000);
    }

    /** @param  array<string, mixed>  $leg */
    private function formatLeg(array $leg): string
    {
        $from = trim((string) ($leg['from'] ?? ''));
        $to = trim((string) ($leg['to'] ?? ''));
        if (($leg['type'] ?? '') === 'walk') {
            $mins = (int) round(((int) ($leg['durationSec'] ?? 0)) / 60);

            return '・'.__('徒歩').' '.$from.' → '.$to.($mins > 0 ? '（'.$mins.__('分').'）' : '');
        }
        $name = trim((string) ($leg['routeName'] ?? ''));
        $board = trim((string) ($leg['boardTime'] ?? ''));
        $alight = trim((string) ($leg['alightTime'] ?? ''));

        return '・'.trim($name.' '.$board.' '.$from.' → '.$alight.' '.$to);
    }
}
