<?php

namespace App\Services;

use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\GroupMessageAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class GroupChatService
{
    public function __construct(private GroupService $groups) {}

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
                $members = collect($this->groups->listMembers($groupId))
                    ->filter(fn (array $m) => (int) $m['userId'] !== $userId)
                    ->map(function (array $m) use ($groupId, $userId) {
                        $peerId = (int) $m['userId'];
                        $last = $this->latestInThread($groupId, $peerId, $userId);

                        return [
                            'userId' => $peerId,
                            'displayName' => $m['displayName'] ?: __('不明'),
                            'initials' => $this->initials((string) ($m['displayName'] ?? '')),
                            'href' => '/messages/'.$groupId.'/dm/'.$peerId,
                            'lastMessageAt' => $last['at'],
                            'lastMessagePreview' => $last['preview'],
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'id' => $groupId,
                    'name' => $group['name'],
                    'href' => '/messages/'.$groupId,
                    'memberCount' => (int) ($group['memberCount'] ?? count($members) + 1),
                    'lastMessageAt' => $lastGroup['at'],
                    'lastMessagePreview' => $lastGroup['preview'],
                    'members' => $members,
                ];
            })
            ->values()
            ->all();
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

        $query = GroupMessage::query()
            ->with(['user', 'recipient', 'attachments'])
            ->where('group_id', $groupId);
        $this->applyThreadScope($query, $userId, $peerUserId);

        if ($afterId !== null && $afterId > 0) {
            return $query
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->get()
                ->map(fn (GroupMessage $message) => $this->messageToArray($message))
                ->all();
        }

        return $query
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->values()
            ->map(fn (GroupMessage $message) => $this->messageToArray($message))
            ->all();
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return array<string, mixed>
     */
    public function send(int $userId, int $groupId, ?string $body, array $files = [], ?int $peerUserId = null): array
    {
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

        return DB::transaction(function () use ($userId, $groupId, $text, $files, $peerUserId) {
            $message = GroupMessage::create([
                'group_id' => $groupId,
                'user_id' => $userId,
                'recipient_user_id' => $peerUserId,
                'body' => $text !== '' ? mb_substr($text, 0, 5000) : null,
            ]);

            foreach ($files as $file) {
                $this->storeAttachment($message, $userId, $file);
            }

            return $this->messageToArray($message->load(['user', 'recipient', 'attachments']));
        });
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

        return $attachment;
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
    public function messageToArray(GroupMessage $message): array
    {
        return [
            'id' => $message->id,
            'groupId' => $message->group_id,
            'userId' => $message->user_id,
            'userName' => $message->user?->display_name ?: __('不明'),
            'recipientUserId' => $message->recipient_user_id,
            'body' => $message->body,
            'createdAt' => $message->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
            'attachments' => $message->attachments
                ->map(fn (GroupMessageAttachment $a) => $this->attachmentToArray($a))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function attachmentToArray(GroupMessageAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'mime' => $attachment->mime,
            'sizeBytes' => (int) $attachment->size_bytes,
            'isImage' => $attachment->isImage(),
            'url' => '/messages/attachments/'.$attachment->id.'/file',
            'downloadUrl' => '/messages/attachments/'.$attachment->id.'/download',
        ];
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
        $query = GroupMessage::query()->where('group_id', $groupId);
        $this->applyThreadScope($query, $userId, $peerUserId);
        $last = $query->orderByDesc('id')->first();

        return [
            'at' => $last?->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
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
