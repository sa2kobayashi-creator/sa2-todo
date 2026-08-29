<?php

namespace App\Services;

use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\GroupMessageAttachment;
use App\Models\GroupMessageHide;
use App\Models\GroupMessageReaction;
use App\Models\GroupMessageThreadRead;
use App\Models\User;
use App\Support\LocaleFormat;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class GroupChatService
{
    public const ONLINE_WITHIN_SECONDS = 120;

    /** @var list<string> */
    public const REACTION_EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '😊'];

    /** @var array<string, int> */
    private array $lastReadCache = [];

    public function __construct(
        private GroupService $groups,
        private WebPushService $push,
    ) {}

    public function maxAttachmentBytes(): int
    {
        return max(1024, (int) config('messages.max_attachment_bytes', 20 * 1024 * 1024));
    }

    public function maxAttachmentsPerMessage(): int
    {
        return max(1, (int) config('messages.max_attachments_per_message', 5));
    }

    /** @return list<string> */
    public function allowedExtensions(): array
    {
        $ext = config('messages.allowed_extensions', []);

        return is_array($ext) ? array_values(array_map('strval', $ext)) : [];
    }

    public function attachmentDisk(): string
    {
        $configured = trim((string) config('messages.attachment_disk', ''));
        if ($configured !== '') {
            return $configured;
        }

        return (string) config('photos.disk', config('filesystems.default', 'local'));
    }

    public function touchPresence(int $userId): void
    {
        User::query()->where('id', $userId)->update(['last_seen_at' => now()]);
    }

    /**
     * サイドバー用: グループ全体スレ + メンバー個別DM。
     *
     * @return list<array<string, mixed>>
     */
    public function listWorkspace(int $userId): array
    {
        return $this->groups->listApprovedForUser($userId)
            ->map(function (array $group) use ($userId) {
                $groupId = (int) $group['id'];
                $lastGroup = $this->latestInThread($groupId, null, $userId);
                $memberRows = collect($this->groups->listMembers($groupId));
                $presenceById = User::query()
                    ->whereIn('id', $memberRows->pluck('userId')->all())
                    ->get(['id', 'last_seen_at'])
                    ->keyBy('id');

                $members = $memberRows
                    ->filter(fn (array $m) => (int) $m['userId'] !== $userId)
                    ->map(function (array $m) use ($groupId, $userId, $presenceById) {
                        $peerId = (int) $m['userId'];
                        $last = $this->latestInThread($groupId, $peerId, $userId);
                        /** @var User|null $peer */
                        $peer = $presenceById->get($peerId);
                        $unreadCount = $this->unreadCountForThread($userId, $groupId, $peerId);

                        return [
                            'userId' => $peerId,
                            'displayName' => $m['displayName'] ?: __('不明'),
                            'initials' => $this->initials((string) ($m['displayName'] ?? '')),
                            'href' => '/messages/'.$groupId.'/dm/'.$peerId,
                            'threadType' => 'dm',
                            'threadTypeLabel' => __('個別チャット'),
                            'online' => $peer?->isOnline(self::ONLINE_WITHIN_SECONDS) ?? false,
                            'lastSeenAt' => $peer?->last_seen_at
                                ? LocaleFormat::dateTime($peer->last_seen_at)
                                : null,
                            'lastMessageAt' => $last['at'],
                            'lastMessagePreview' => $last['preview'],
                            'unreadCount' => $unreadCount,
                        ];
                    })
                    ->values()
                    ->all();

                $channelUnread = $this->unreadCountForThread($userId, $groupId, null);

                return [
                    'id' => $groupId,
                    'name' => $group['name'],
                    'href' => '/messages/'.$groupId,
                    'threadType' => 'channel',
                    'threadTypeLabel' => __('グループチャット'),
                    'memberCount' => (int) ($group['memberCount'] ?? count($members) + 1),
                    'lastMessageAt' => $lastGroup['at'],
                    'lastMessagePreview' => $lastGroup['preview'],
                    'unreadCount' => $channelUnread,
                    'members' => $members,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * インボックス下部用：全グループの最近のグループ全体メッセージ（重複なく1一覧）。
     *
     * @return list<array{id: int, groupId: int, groupName: string, userName: string, preview: string, createdAt: ?string, href: string}>
     */
    public function inboxRecentGroupMessages(int $userId, int $limit = 20): array
    {
        $limit = max(1, min(40, $limit));
        $groups = $this->groups->listApprovedForUser($userId);
        if ($groups->isEmpty()) {
            return [];
        }

        $groupNames = [];
        $groupIds = [];
        foreach ($groups as $group) {
            $id = (int) $group['id'];
            $groupIds[] = $id;
            $groupNames[$id] = (string) ($group['name'] ?? '');
        }

        return GroupMessage::query()
            ->with('user')
            ->whereIn('group_id', $groupIds)
            ->whereNull('recipient_user_id')
            ->whereDoesntHave('hides', fn (Builder $q) => $q->where('user_id', $userId))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (GroupMessage $message) use ($groupNames, $userId) {
                $groupId = (int) $message->group_id;
                $preview = trim((string) ($message->body ?: ''));
                if ($preview === '') {
                    $preview = __('（添付）');
                }
                $lastRead = $this->lastReadMessageId($userId, $groupId, null);
                $isUnread = (int) $message->user_id !== $userId && (int) $message->id > $lastRead;

                return [
                    'id' => (int) $message->id,
                    'groupId' => $groupId,
                    'groupName' => $groupNames[$groupId] ?? '',
                    'userName' => $message->user?->display_name ?: __('不明'),
                    'preview' => mb_substr($preview, 0, 100),
                    'createdAt' => $message->created_at ? LocaleFormat::dateTime($message->created_at) : null,
                    'href' => '/messages/'.$groupId.'#msg-'.$message->id,
                    'isUnread' => $isUnread,
                ];
            })
            ->values()
            ->all();
    }

    public function peerKey(?int $peerUserId): int
    {
        return $peerUserId !== null && $peerUserId > 0 ? $peerUserId : 0;
    }

    public function lastReadMessageId(int $userId, int $groupId, ?int $peerUserId): int
    {
        $cacheKey = $userId.'|'.$groupId.'|'.$this->peerKey($peerUserId);
        if (array_key_exists($cacheKey, $this->lastReadCache)) {
            return $this->lastReadCache[$cacheKey];
        }

        $row = GroupMessageThreadRead::query()
            ->where('user_id', $userId)
            ->where('group_id', $groupId)
            ->where('peer_user_id', $this->peerKey($peerUserId))
            ->first();

        if ($row) {
            return $this->lastReadCache[$cacheKey] = (int) $row->last_read_message_id;
        }

        // 記録なし＝導入前は既読扱い。新規投稿時に firstOrCreate で追跡開始する。
        return $this->lastReadCache[$cacheKey] = $this->maxMessageIdInThread($groupId, $peerUserId, $userId);
    }

    public function markThreadRead(int $userId, int $groupId, ?int $peerUserId, int $messageId): void
    {
        if ($userId <= 0 || $groupId <= 0 || $messageId <= 0) {
            return;
        }

        $peerKey = $this->peerKey($peerUserId);
        $cacheKey = $userId.'|'.$groupId.'|'.$peerKey;
        $row = GroupMessageThreadRead::query()->firstOrNew([
            'user_id' => $userId,
            'group_id' => $groupId,
            'peer_user_id' => $peerKey,
        ]);
        if ((int) $row->last_read_message_id >= $messageId) {
            $this->lastReadCache[$cacheKey] = (int) $row->last_read_message_id;

            return;
        }
        $row->last_read_message_id = $messageId;
        $row->save();
        $this->lastReadCache[$cacheKey] = $messageId;
        $this->forgetUnreadCountCache($userId);
    }

    public function unreadCountForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        return (int) Cache::remember('chat:unread:'.$userId, 30, function () use ($userId) {
            $total = 0;
            foreach ($this->groups->listApprovedForUser($userId) as $group) {
                $groupId = (int) $group['id'];
                $total += $this->unreadCountForThread($userId, $groupId, null);
                foreach ($this->groups->listMembers($groupId) as $member) {
                    $peerId = (int) ($member['userId'] ?? 0);
                    if ($peerId <= 0 || $peerId === $userId) {
                        continue;
                    }
                    $total += $this->unreadCountForThread($userId, $groupId, $peerId);
                }
            }

            return $total;
        });
    }

    public function forgetUnreadCountCache(int $userId): void
    {
        if ($userId > 0) {
            Cache::forget('chat:unread:'.$userId);
        }
    }

    public function unreadCountForThread(int $userId, int $groupId, ?int $peerUserId): int
    {
        $lastRead = $this->lastReadMessageId($userId, $groupId, $peerUserId);
        $query = GroupMessage::query()
            ->where('group_id', $groupId)
            ->where('user_id', '!=', $userId)
            ->where('id', '>', $lastRead)
            ->whereDoesntHave('hides', fn (Builder $q) => $q->where('user_id', $userId));
        $this->applyThreadScope($query, $userId, $peerUserId);

        return (int) $query->count();
    }

    /**
     * ダッシュボード用：未読件数と直近の未読プレビュー。
     *
     * @return array{count: int, items: list<array{id: int, href: string, userName: string, preview: string, groupName: string, createdAt: ?string, isDirect: bool}>}
     */
    public function unreadSummaryForDashboard(int $userId, int $limit = 5): array
    {
        $count = $this->unreadCountForUser($userId);
        if ($count <= 0) {
            return ['count' => 0, 'items' => []];
        }

        $items = [];
        $groups = $this->groups->listApprovedForUser($userId);
        foreach ($groups as $group) {
            $groupId = (int) $group['id'];
            $groupName = (string) ($group['name'] ?? '');

            $channelLastRead = $this->lastReadMessageId($userId, $groupId, null);
            $channelMsgs = GroupMessage::query()
                ->with('user')
                ->where('group_id', $groupId)
                ->whereNull('recipient_user_id')
                ->where('user_id', '!=', $userId)
                ->where('id', '>', $channelLastRead)
                ->whereDoesntHave('hides', fn (Builder $q) => $q->where('user_id', $userId))
                ->orderByDesc('id')
                ->limit($limit)
                ->get();
            foreach ($channelMsgs as $message) {
                $items[] = $this->unreadPreviewItem($message, $groupName, false);
            }

            foreach ($this->groups->listMembers($groupId) as $member) {
                $peerId = (int) ($member['userId'] ?? 0);
                if ($peerId <= 0 || $peerId === $userId) {
                    continue;
                }
                $dmLastRead = $this->lastReadMessageId($userId, $groupId, $peerId);
                $dmMsgs = GroupMessage::query()
                    ->with('user')
                    ->where('group_id', $groupId)
                    ->where('user_id', '!=', $userId)
                    ->where('id', '>', $dmLastRead)
                    ->whereDoesntHave('hides', fn (Builder $q) => $q->where('user_id', $userId));
                $this->applyThreadScope($dmMsgs, $userId, $peerId);
                foreach ($dmMsgs->orderByDesc('id')->limit($limit)->get() as $message) {
                    $items[] = $this->unreadPreviewItem($message, $groupName, true);
                }
            }
        }

        usort($items, static fn (array $a, array $b) => ($b['id'] <=> $a['id']));
        $items = array_slice($items, 0, $limit);

        return ['count' => $count, 'items' => $items];
    }

    /** @return array{id: int, href: string, userName: string, preview: string, groupName: string, createdAt: ?string, isDirect: bool} */
    private function unreadPreviewItem(GroupMessage $message, string $groupName, bool $isDirect): array
    {
        $preview = trim((string) ($message->body ?: ''));
        if ($preview === '') {
            $preview = __('（添付）');
        }
        $groupId = (int) $message->group_id;
        $href = $isDirect
            ? '/messages/'.$groupId.'/dm/'.(int) $message->user_id.'#msg-'.$message->id
            : '/messages/'.$groupId.'#msg-'.$message->id;

        return [
            'id' => (int) $message->id,
            'href' => $href,
            'userName' => $message->user?->display_name ?: __('不明'),
            'preview' => mb_substr($preview, 0, 80),
            'groupName' => $groupName,
            'createdAt' => $message->created_at ? LocaleFormat::dateTime($message->created_at) : null,
            'isDirect' => $isDirect,
        ];
    }

    private function touchThreadReadsAfterSend(int $senderId, int $groupId, ?int $peerUserId, int $messageId): void
    {
        $this->markThreadRead($senderId, $groupId, $peerUserId, $messageId);

        $recipientIds = [];
        if ($peerUserId !== null) {
            $recipientIds[] = $peerUserId;
        } else {
            foreach ($this->groups->listMembers($groupId) as $member) {
                $uid = (int) ($member['userId'] ?? 0);
                if ($uid > 0 && $uid !== $senderId) {
                    $recipientIds[] = $uid;
                }
            }
        }

        foreach (array_unique($recipientIds) as $uid) {
            // DM では相手側の peer_key は送信者
            $peerKey = $peerUserId !== null ? $senderId : 0;
            GroupMessageThreadRead::query()->firstOrCreate(
                [
                    'user_id' => $uid,
                    'group_id' => $groupId,
                    'peer_user_id' => $peerKey,
                ],
                ['last_read_message_id' => max(0, $messageId - 1)]
            );
            unset($this->lastReadCache[$uid.'|'.$groupId.'|'.$peerKey]);
            $this->forgetUnreadCountCache((int) $uid);
        }
    }

    private function maxMessageIdInThread(int $groupId, ?int $peerUserId, int $userId): int
    {
        $query = GroupMessage::query()
            ->where('group_id', $groupId)
            ->whereDoesntHave('hides', fn (Builder $q) => $q->where('user_id', $userId));
        $this->applyThreadScope($query, $userId, $peerUserId);

        return (int) ($query->max('id') ?? 0);
    }

    /**
     * @return array<int, array{online: bool, lastSeenAt: ?string}>
     */
    public function presenceForGroup(int $groupId): array
    {
        $ids = collect($this->groups->listMembers($groupId))->pluck('userId')->all();
        $out = [];
        foreach (User::query()->whereIn('id', $ids)->get(['id', 'last_seen_at']) as $user) {
            $out[(int) $user->id] = [
                'online' => $user->isOnline(self::ONLINE_WITHIN_SECONDS),
                'lastSeenAt' => $user->last_seen_at
                    ? LocaleFormat::dateTime($user->last_seen_at)
                    : null,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listMessages(int $userId, int $groupId, ?int $peerUserId = null, ?int $afterId = null, int $limit = 100): array
    {
        $this->assertMember($userId, $groupId);
        if ($peerUserId !== null) {
            $this->assertPeer($userId, $groupId, $peerUserId);
        }

        $query = $this->threadQuery($userId, $groupId, $peerUserId);
        $lastReadId = $this->lastReadMessageId($userId, $groupId, $peerUserId);

        if ($afterId !== null && $afterId > 0) {
            return $query
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->get()
                ->map(fn (GroupMessage $message) => $this->messageToArray($message, $userId, $lastReadId))
                ->all();
        }

        return $query
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->values()
            ->map(fn (GroupMessage $message) => $this->messageToArray($message, $userId, $lastReadId))
            ->all();
    }

    /**
     * @return array{messages: list<array<string, mixed>>, events: list<array<string, mixed>>}
     */
    public function pollThread(int $userId, int $groupId, ?int $peerUserId, int $afterId, ?string $sinceIso = null): array
    {
        $messages = $this->listMessages(
            $userId,
            $groupId,
            $peerUserId,
            $afterId > 0 ? $afterId : null
        );

        $events = [];
        if ($sinceIso) {
            try {
                $since = Carbon::parse($sinceIso);
            } catch (\Throwable) {
                $since = null;
            }
            if ($since) {
                $events = $this->threadEventsSince($userId, $groupId, $peerUserId, $since, $afterId);
            }
        }

        $latestId = 0;
        foreach ($messages as $message) {
            $latestId = max($latestId, (int) ($message['id'] ?? 0));
        }
        if ($latestId <= 0) {
            $latestId = $this->maxMessageIdInThread($groupId, $peerUserId, $userId);
        }
        if ($latestId > 0) {
            $this->markThreadRead($userId, $groupId, $peerUserId, $latestId);
        }

        return [
            'messages' => $messages,
            'events' => $events,
            'unreadCount' => $this->unreadCountForUser($userId),
        ];
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return array<string, mixed>
     */
    public function send(
        int $userId,
        int $groupId,
        ?string $body,
        array $files = [],
        ?int $peerUserId = null,
        ?int $replyToId = null,
    ): array {
        $this->assertMember($userId, $groupId);
        if ($peerUserId !== null) {
            $this->assertPeer($userId, $groupId, $peerUserId);
        }

        $text = trim((string) $body);
        $files = array_values(array_filter($files, fn ($f) => $f instanceof UploadedFile && $f->isValid()));

        if ($text === '' && $files === []) {
            throw new \InvalidArgumentException(__('メッセージか添付ファイルを入力してください。'));
        }

        if (count($files) > $this->maxAttachmentsPerMessage()) {
            throw new \InvalidArgumentException(__('添付は最大 :count 件までです。', [
                'count' => $this->maxAttachmentsPerMessage(),
            ]));
        }

        $reply = null;
        if ($replyToId !== null) {
            $reply = $this->findAccessibleMessage($userId, $replyToId);
            if ((int) $reply->group_id !== $groupId) {
                throw new \InvalidArgumentException(__('返信先のメッセージが無効です。'));
            }
            if ($peerUserId === null && $reply->recipient_user_id !== null) {
                throw new \InvalidArgumentException(__('返信先のメッセージが無効です。'));
            }
            if ($peerUserId !== null) {
                $ok = (
                    ((int) $reply->user_id === $userId && (int) $reply->recipient_user_id === $peerUserId)
                    || ((int) $reply->user_id === $peerUserId && (int) $reply->recipient_user_id === $userId)
                );
                if (! $ok) {
                    throw new \InvalidArgumentException(__('返信先のメッセージが無効です。'));
                }
            }
        }

        $payload = DB::transaction(function () use ($userId, $groupId, $text, $files, $peerUserId, $reply) {
            $message = GroupMessage::create([
                'group_id' => $groupId,
                'user_id' => $userId,
                'recipient_user_id' => $peerUserId,
                'reply_to_id' => $reply?->id,
                'body' => $text !== '' ? mb_substr($text, 0, 5000) : null,
            ]);

            foreach ($files as $file) {
                $this->storeAttachment($message, $userId, $file);
            }

            $this->touchThreadReadsAfterSend($userId, $groupId, $peerUserId, (int) $message->id);

            return $this->messageToArray(
                $message->load(['user', 'recipient', 'attachments', 'replyTo.user', 'reactions']),
                $userId
            );
        });

        $this->notifyNewMessage($userId, $groupId, $peerUserId, $payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function notifyNewMessage(int $senderId, int $groupId, ?int $peerUserId, array $message): void
    {
        try {
            $recipientIds = $peerUserId !== null
                ? [$peerUserId]
                : collect($this->groups->listMembers($groupId))
                    ->pluck('userId')
                    ->map(fn ($id) => (int) $id)
                    ->reject(fn (int $id) => $id === $senderId)
                    ->unique()
                    ->values()
                    ->all();

            if ($recipientIds === []) {
                return;
            }

            $senderName = trim((string) ($message['userName'] ?? ''));
            if ($senderName === '') {
                $senderName = __('不明');
            }

            $text = trim((string) ($message['body'] ?? ''));
            if ($text !== '') {
                $preview = mb_strlen($text) > 80 ? mb_substr($text, 0, 80).'…' : $text;
            } elseif (! empty($message['attachments'])) {
                $preview = __('添付ファイル');
            } else {
                $preview = __('新しいメッセージ');
            }

            $url = $peerUserId !== null
                ? '/messages/'.$groupId.'/dm/'.$senderId
                : '/messages/'.$groupId;
            $tag = $peerUserId !== null
                ? 'sa2-msg-'.$groupId.'-dm-'.$senderId
                : 'sa2-msg-'.$groupId.'-ch';

            $body = $peerUserId !== null
                ? __(':name からメッセージ: :preview', ['name' => $senderName, 'preview' => $preview])
                : __(':name（:group）: :preview', [
                    'name' => $senderName,
                    'group' => (string) (Group::query()->find($groupId)?->name ?: __('グループ')),
                    'preview' => $preview,
                ]);

            $payload = [
                'title' => __('新着メッセージ'),
                'body' => $body,
                'url' => $url,
                'tag' => $tag,
                'ttl' => 86400,
                'urgency' => 'normal',
            ];

            User::query()
                ->whereIn('id', $recipientIds)
                ->get()
                ->each(fn (User $user) => $this->push->notify($user, $payload));
        } catch (\Throwable) {
            // 通知失敗でメッセージ送信は止めない
        }
    }

    /** @return array<string, mixed> */
    public function edit(int $userId, int $messageId, string $body): array
    {
        $message = $this->findAccessibleMessage($userId, $messageId);
        if ((int) $message->user_id !== $userId) {
            throw new \InvalidArgumentException(__('自分のメッセージのみ編集できます。'));
        }

        $text = trim($body);
        if ($text === '') {
            throw new \InvalidArgumentException(__('本文を入力してください。'));
        }

        $message->body = mb_substr($text, 0, 5000);
        $message->edited_at = now();
        $message->save();

        return $this->messageToArray(
            $message->load(['user', 'recipient', 'attachments', 'replyTo.user', 'reactions']),
            $userId
        );
    }

    /**
     * 送信者: 全員から削除 / 受信者: 自分の一覧から非表示
     */
    public function deleteForUser(int $userId, int $messageId): array
    {
        $message = $this->findAccessibleMessage($userId, $messageId);

        if ((int) $message->user_id === $userId) {
            $message->delete();

            return ['id' => $messageId, 'deleted' => true, 'scope' => 'everyone'];
        }

        GroupMessageHide::query()->firstOrCreate([
            'group_message_id' => $messageId,
            'user_id' => $userId,
        ]);

        return ['id' => $messageId, 'deleted' => true, 'scope' => 'me'];
    }

    /** @return array<string, mixed> */
    public function toggleReaction(int $userId, int $messageId, string $emoji): array
    {
        $emoji = trim($emoji);
        if (! in_array($emoji, self::REACTION_EMOJIS, true)) {
            throw new \InvalidArgumentException(__('このリアクションは使えません。'));
        }

        $message = $this->findAccessibleMessage($userId, $messageId);
        $existing = GroupMessageReaction::query()
            ->where('group_message_id', $message->id)
            ->where('user_id', $userId)
            ->where('emoji', $emoji)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            GroupMessageReaction::create([
                'group_message_id' => $message->id,
                'user_id' => $userId,
                'emoji' => $emoji,
            ]);
        }

        return $this->messageToArray(
            $message->fresh()->load(['user', 'recipient', 'attachments', 'replyTo.user', 'reactions']),
            $userId
        );
    }

    /** @return array<string, mixed> */
    public function forward(int $userId, int $messageId, int $toGroupId, ?int $toPeerUserId = null): array
    {
        $source = $this->findAccessibleMessage($userId, $messageId);
        $body = trim((string) ($source->body ?? ''));
        if ($body === '') {
            $body = __('（添付）');
        }

        $forwardBody = __('【転送】')."\n".$body;

        return $this->send($userId, $toGroupId, $forwardBody, [], $toPeerUserId);
    }

    public function findAccessibleAttachment(int $userId, int $attachmentId): ?GroupMessageAttachment
    {
        $attachment = GroupMessageAttachment::query()->with('message')->find($attachmentId);
        if (! $attachment || ! $attachment->message) {
            return null;
        }

        $message = $attachment->message;
        if (! $this->groups->userBelongsToApprovedGroup($userId, (int) $message->group_id)) {
            return null;
        }

        if ($message->recipient_user_id !== null) {
            $allowed = [(int) $message->user_id, (int) $message->recipient_user_id];
            if (! in_array($userId, $allowed, true)) {
                return null;
            }
        }

        if (GroupMessageHide::query()
            ->where('group_message_id', $message->id)
            ->where('user_id', $userId)
            ->exists()) {
            return null;
        }

        return $attachment;
    }

    public function findAccessibleMessage(int $userId, int $messageId): GroupMessage
    {
        $message = GroupMessage::query()
            ->with(['user', 'recipient', 'attachments', 'replyTo.user', 'reactions'])
            ->find($messageId);

        if (! $message) {
            throw new \InvalidArgumentException(__('メッセージが見つかりません。'));
        }

        if (! $this->groups->userBelongsToApprovedGroup($userId, (int) $message->group_id)) {
            throw new \InvalidArgumentException(__('このメッセージを表示する権限がありません。'));
        }

        if ($message->recipient_user_id !== null) {
            $allowed = [(int) $message->user_id, (int) $message->recipient_user_id];
            if (! in_array($userId, $allowed, true)) {
                throw new \InvalidArgumentException(__('このメッセージを表示する権限がありません。'));
            }
        }

        if (GroupMessageHide::query()
            ->where('group_message_id', $message->id)
            ->where('user_id', $userId)
            ->exists()) {
            throw new \InvalidArgumentException(__('メッセージが見つかりません。'));
        }

        return $message;
    }

    public function assertMember(int $userId, int $groupId): Group
    {
        if (! $this->groups->userBelongsToApprovedGroup($userId, $groupId)) {
            throw new \InvalidArgumentException(__('このグループのメッセージを表示する権限がありません。'));
        }

        $group = Group::query()->find($groupId);
        if (! $group) {
            throw new \InvalidArgumentException(__('グループが見つかりません。'));
        }

        return $group;
    }

    public function assertPeer(int $userId, int $groupId, int $peerUserId): User
    {
        if ($peerUserId === $userId) {
            throw new \InvalidArgumentException(__('自分自身には送れません。'));
        }
        if (! $this->groups->userBelongsToApprovedGroup($peerUserId, $groupId)) {
            throw new \InvalidArgumentException(__('相手はこのグループのメンバーではありません。'));
        }

        $peer = User::query()->find($peerUserId);
        if (! $peer) {
            throw new \InvalidArgumentException(__('相手ユーザーが見つかりません。'));
        }

        return $peer;
    }

    /** @return array<string, mixed> */
    public function messageToArray(GroupMessage $message, ?int $viewerUserId = null, ?int $lastReadMessageId = null): array
    {
        $isDirect = $message->isDirect();
        $reactions = $message->relationLoaded('reactions')
            ? $message->reactions
            : $message->reactions()->get();

        $grouped = [];
        foreach ($reactions as $reaction) {
            $emoji = (string) $reaction->emoji;
            if (! isset($grouped[$emoji])) {
                $grouped[$emoji] = ['emoji' => $emoji, 'count' => 0, 'reactedByMe' => false];
            }
            $grouped[$emoji]['count']++;
            if ($viewerUserId !== null && (int) $reaction->user_id === $viewerUserId) {
                $grouped[$emoji]['reactedByMe'] = true;
            }
        }

        $reply = null;
        if ($message->reply_to_id) {
            $parent = $message->relationLoaded('replyTo') ? $message->replyTo : null;
            if ($parent && ! $parent->trashed()) {
                $reply = [
                    'id' => $parent->id,
                    'userName' => $parent->user?->display_name ?: __('不明'),
                    'body' => mb_substr(trim((string) ($parent->body ?: __('（添付）'))), 0, 120),
                ];
            }
        }

        $isUnread = false;
        if ($viewerUserId !== null && (int) $message->user_id !== $viewerUserId) {
            $threshold = $lastReadMessageId;
            if ($threshold === null) {
                $peer = $message->recipient_user_id !== null
                    ? ((int) $message->user_id === $viewerUserId
                        ? (int) $message->recipient_user_id
                        : (int) $message->user_id)
                    : null;
                $threshold = $this->lastReadMessageId($viewerUserId, (int) $message->group_id, $peer);
            }
            $isUnread = (int) $message->id > (int) $threshold;
        }

        return [
            'id' => $message->id,
            'groupId' => $message->group_id,
            'userId' => $message->user_id,
            'userName' => $message->user?->display_name ?: __('不明'),
            'recipientUserId' => $message->recipient_user_id,
            'isDirect' => $isDirect,
            'threadType' => $isDirect ? 'dm' : 'channel',
            'threadTypeLabel' => $isDirect ? __('個別メッセージ') : __('グループメッセージ'),
            'body' => $message->body,
            'isSticker' => $this->isStickerBody($message->body) && $message->attachments->isEmpty(),
            'isUnread' => $isUnread,
            'createdAt' => $message->created_at ? LocaleFormat::dateTime($message->created_at) : null,
            'editedAt' => $message->edited_at ? LocaleFormat::dateTime($message->edited_at) : null,
            'replyTo' => $reply,
            'reactions' => array_values($grouped),
            'attachments' => $message->attachments
                ->map(fn (GroupMessageAttachment $a) => $this->attachmentToArray($a))
                ->values()
                ->all(),
        ];
    }

    /**
     * Messenger風の大きな絵文字/OK送信かどうか（本文が絵文字のみ）。
     */
    public function isStickerBody(?string $body): bool
    {
        $text = trim((string) $body);
        if ($text === '' || mb_strlen($text) > 12) {
            return false;
        }
        if (preg_match('/[\p{L}\p{N}]/u', $text)) {
            return false;
        }

        // 絵文字・記号・ZWJ・異体字セレクタのみ
        return (bool) preg_match('/^[\p{So}\p{Sk}\x{200D}\x{FE0E}\x{FE0F}\x{1F3FB}-\x{1F3FF}\x{20E3}\s]+$/u', $text);
    }

    /** @return array<string, mixed> */
    public function attachmentToArray(GroupMessageAttachment $attachment): array
    {
        $photos = app(PhotoService::class);

        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'mime' => $attachment->mime,
            'sizeBytes' => (int) $attachment->size_bytes,
            'isImage' => $attachment->isImage(),
            'canSaveToPhotos' => $photos->canImportMimeAndName($attachment->mime, $attachment->original_name),
            'url' => '/messages/attachments/'.$attachment->id.'/file',
            'downloadUrl' => '/messages/attachments/'.$attachment->id.'/download',
            'saveToPhotosUrl' => '/messages/attachments/'.$attachment->id.'/to-photos',
        ];
    }

    private function threadQuery(int $userId, int $groupId, ?int $peerUserId): Builder
    {
        $query = GroupMessage::query()
            ->with(['user', 'recipient', 'attachments', 'replyTo.user', 'reactions'])
            ->where('group_id', $groupId)
            ->whereDoesntHave('hides', fn (Builder $q) => $q->where('user_id', $userId));
        $this->applyThreadScope($query, $userId, $peerUserId);

        return $query;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function threadEventsSince(
        int $userId,
        int $groupId,
        ?int $peerUserId,
        Carbon $since,
        int $afterId,
    ): array {
        $events = [];

        $editedQuery = GroupMessage::query()
            ->with(['user', 'recipient', 'attachments', 'replyTo.user', 'reactions'])
            ->where('group_id', $groupId)
            ->where('edited_at', '>', $since)
            ->whereDoesntHave('hides', fn (Builder $q) => $q->where('user_id', $userId));
        $this->applyThreadScope($editedQuery, $userId, $peerUserId);
        if ($afterId > 0) {
            $editedQuery->where('id', '<=', $afterId);
        }
        foreach ($editedQuery->get() as $message) {
            $events[] = ['type' => 'edited', 'message' => $this->messageToArray($message, $userId)];
        }

        $deletedQuery = GroupMessage::onlyTrashed()
            ->where('group_id', $groupId)
            ->where('deleted_at', '>', $since);
        $this->applyThreadScope($deletedQuery, $userId, $peerUserId);
        foreach ($deletedQuery->get(['id']) as $message) {
            $events[] = ['type' => 'deleted', 'id' => (int) $message->id];
        }

        $reactedIds = GroupMessageReaction::query()
            ->where(function (Builder $q) use ($since) {
                $q->where('created_at', '>', $since)->orWhere('updated_at', '>', $since);
            })
            ->whereHas('message', function (Builder $q) use ($userId, $groupId, $peerUserId) {
                $q->where('group_id', $groupId);
                $this->applyThreadScope($q, $userId, $peerUserId);
            })
            ->pluck('group_message_id')
            ->unique()
            ->all();

        if ($reactedIds !== []) {
            $reactQuery = GroupMessage::query()
                ->with(['user', 'recipient', 'attachments', 'replyTo.user', 'reactions'])
                ->whereIn('id', $reactedIds)
                ->whereDoesntHave('hides', fn (Builder $q) => $q->where('user_id', $userId));
            foreach ($reactQuery->get() as $message) {
                $events[] = ['type' => 'reacted', 'message' => $this->messageToArray($message, $userId)];
            }
        }

        return $events;
    }

    private function applyThreadScope(Builder $query, int $userId, ?int $peerUserId): void
    {
        if ($peerUserId === null) {
            $query->whereNull('recipient_user_id');

            return;
        }

        $query->where(function (Builder $q) use ($userId, $peerUserId) {
            $q->where(function (Builder $inner) use ($userId, $peerUserId) {
                $inner->where('user_id', $userId)->where('recipient_user_id', $peerUserId);
            })->orWhere(function (Builder $inner) use ($userId, $peerUserId) {
                $inner->where('user_id', $peerUserId)->where('recipient_user_id', $userId);
            });
        });
    }

    /** @return array{at: ?string, preview: ?string} */
    private function latestInThread(int $groupId, ?int $peerUserId, int $userId): array
    {
        $query = GroupMessage::query()
            ->where('group_id', $groupId)
            ->whereDoesntHave('hides', fn (Builder $q) => $q->where('user_id', $userId));
        $this->applyThreadScope($query, $userId, $peerUserId);
        $last = $query->orderByDesc('id')->first();

        return [
            'at' => $last?->created_at ? LocaleFormat::dateTime($last->created_at) : null,
            'preview' => $last
                ? mb_substr(trim((string) ($last->body ?: __('（添付）'))), 0, 80)
                : null,
        ];
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) >= 2) {
            return mb_substr($parts[0], 0, 1).mb_substr($parts[array_key_last($parts)], 0, 1);
        }
        if ($parts !== []) {
            $first = $parts[0];

            return mb_substr($first, 0, preg_match('/^[A-Za-z]/u', $first) ? 2 : 1);
        }

        return '?';
    }

    private function storeAttachment(GroupMessage $message, int $userId, UploadedFile $file): GroupMessageAttachment
    {
        $maxBytes = $this->maxAttachmentBytes();
        if ($file->getSize() > $maxBytes) {
            throw new \InvalidArgumentException(__('添付ファイルは :size 以下にしてください。', [
                'size' => $this->formatBytes($maxBytes),
            ]));
        }

        $ext = strtolower((string) $file->getClientOriginalExtension());
        if ($ext === '' || ! in_array($ext, $this->allowedExtensions(), true)) {
            throw new \InvalidArgumentException(__('この形式のファイルは添付できません。'));
        }

        $diskName = $this->attachmentDisk();
        $dir = 'messages/'.$message->group_id.'/'.$message->id;
        $basename = str_replace('.', '', uniqid('ma_', true)).'.'.$ext;

        try {
            $path = Storage::disk($diskName)->putFileAs($dir, $file, $basename, [
                'visibility' => 'private',
                'ContentType' => $file->getMimeType() ?: 'application/octet-stream',
            ]);
        } catch (\Throwable $e) {
            report($e);
            throw new \RuntimeException(__('添付ファイルの保存に失敗しました。'));
        }

        if (! is_string($path) || $path === '') {
            throw new \RuntimeException(__('添付ファイルの保存に失敗しました。'));
        }

        return GroupMessageAttachment::create([
            'group_message_id' => $message->id,
            'user_id' => $userId,
            'disk' => $diskName,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName() ?: ('file.'.$ext), 0, 255),
            'mime' => $file->getMimeType() ?: null,
            'size_bytes' => (int) $file->getSize(),
        ]);
    }

    public const WALLPAPER_THEMES = [
        'default', 'mint', 'sky', 'dusk', 'paper', 'plain',
        'ocean', 'sakura', 'forest', 'sand', 'midnight', 'citrus', 'aurora', 'peach', 'slate', 'meadow',
    ];

    public function maxWallpaperBytes(): int
    {
        return max(1024, (int) config('messages.max_wallpaper_bytes', 3 * 1024 * 1024));
    }

    /**
     * グループチャット共有背景（DM では使わない）。
     *
     * @return array{type: string, value: ?string, url: ?string, updatedAt: ?string}
     */
    public function wallpaperForGroup(int $userId, int $groupId): array
    {
        $group = $this->assertMember($userId, $groupId);

        return $this->wallpaperToArray($group);
    }

    /**
     * @return array{type: string, value: ?string, url: ?string, updatedAt: ?string}
     */
    public function setGroupWallpaperTheme(int $userId, int $groupId, string $theme): array
    {
        $group = $this->assertMember($userId, $groupId);
        $theme = strtolower(trim($theme));
        if (! in_array($theme, self::WALLPAPER_THEMES, true)) {
            throw new \InvalidArgumentException(__('その背景テーマは使えません。'));
        }

        $this->deleteGroupWallpaperFile($group);

        if ($theme === 'default') {
            $group->forceFill([
                'chat_bg_type' => null,
                'chat_bg_theme' => null,
                'chat_bg_disk' => null,
                'chat_bg_path' => null,
                'chat_bg_updated_at' => now(),
            ])->save();
        } else {
            $group->forceFill([
                'chat_bg_type' => 'theme',
                'chat_bg_theme' => $theme,
                'chat_bg_disk' => null,
                'chat_bg_path' => null,
                'chat_bg_updated_at' => now(),
            ])->save();
        }

        return $this->wallpaperToArray($group->fresh());
    }

    /**
     * @return array{type: string, value: ?string, url: ?string, updatedAt: ?string}
     */
    public function setGroupWallpaperImage(int $userId, int $groupId, UploadedFile $file): array
    {
        $group = $this->assertMember($userId, $groupId);
        $maxBytes = $this->maxWallpaperBytes();
        if ($file->getSize() > $maxBytes) {
            throw new \InvalidArgumentException(__('背景画像は :size 以下にしてください。', [
                'size' => $this->formatBytes($maxBytes),
            ]));
        }

        $mime = strtolower((string) ($file->getMimeType() ?: ''));
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if ($ext === '' || ! in_array($ext, $allowed, true)) {
            throw new \InvalidArgumentException(__('背景には画像ファイルを選んでください。'));
        }
        if ($mime !== '' && ! str_starts_with($mime, 'image/')) {
            throw new \InvalidArgumentException(__('背景には画像ファイルを選んでください。'));
        }

        $diskName = $this->attachmentDisk();
        $dir = 'messages/'.$groupId.'/wallpaper';
        $basename = str_replace('.', '', uniqid('bg_', true)).'.'.$ext;

        try {
            $path = Storage::disk($diskName)->putFileAs($dir, $file, $basename, [
                'visibility' => 'private',
                'ContentType' => $file->getMimeType() ?: 'application/octet-stream',
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException(__('背景の保存に失敗しました。'), 0, $e);
        }

        $this->deleteGroupWallpaperFile($group);

        $group->forceFill([
            'chat_bg_type' => 'image',
            'chat_bg_theme' => null,
            'chat_bg_disk' => $diskName,
            'chat_bg_path' => $path,
            'chat_bg_updated_at' => now(),
        ])->save();

        return $this->wallpaperToArray($group->fresh());
    }

    /**
     * @return array{type: string, value: ?string, url: ?string, updatedAt: ?string}
     */
    public function clearGroupWallpaper(int $userId, int $groupId): array
    {
        return $this->setGroupWallpaperTheme($userId, $groupId, 'default');
    }

    public function streamGroupWallpaper(int $userId, int $groupId): array
    {
        $group = $this->assertMember($userId, $groupId);
        if (($group->chat_bg_type ?? null) !== 'image' || ! $group->chat_bg_path) {
            throw new \InvalidArgumentException(__('背景画像が見つかりません。'));
        }

        $disk = (string) ($group->chat_bg_disk ?: $this->attachmentDisk());

        return [
            'disk' => $disk,
            'path' => (string) $group->chat_bg_path,
            'name' => basename((string) $group->chat_bg_path),
        ];
    }

    /**
     * @return array{type: string, value: ?string, url: ?string, updatedAt: ?string}
     */
    public function wallpaperToArray(Group $group): array
    {
        $updatedAt = $group->chat_bg_updated_at?->timezone(config('app.timezone'))->toIso8601String();
        $type = (string) ($group->chat_bg_type ?? '');

        if ($type === 'image' && $group->chat_bg_path) {
            return [
                'type' => 'image',
                'value' => null,
                'url' => '/messages/'.$group->id.'/wallpaper?v='.($group->chat_bg_updated_at?->timestamp ?? time()),
                'updatedAt' => $updatedAt,
            ];
        }

        if ($type === 'theme' && $group->chat_bg_theme) {
            return [
                'type' => 'theme',
                'value' => (string) $group->chat_bg_theme,
                'url' => null,
                'updatedAt' => $updatedAt,
            ];
        }

        return [
            'type' => 'theme',
            'value' => 'default',
            'url' => null,
            'updatedAt' => $updatedAt,
        ];
    }

    private function deleteGroupWallpaperFile(Group $group): void
    {
        $path = (string) ($group->chat_bg_path ?? '');
        if ($path === '') {
            return;
        }
        $disk = (string) ($group->chat_bg_disk ?: $this->attachmentDisk());
        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable) {
            // ignore missing/remote cleanup failures
        }
    }

    private function formatBytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }
}
