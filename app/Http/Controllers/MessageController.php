<?php

namespace App\Http\Controllers;

use App\Exceptions\UsageLimitExceededException;
use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Services\GroupChatService;
use App\Services\LiveKitCallService;
use App\Services\PhotoService;
use App\Services\TranslationService;
use App\Services\UserUsageLimitService;
use App\Support\SafeAttachmentResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageController extends Controller
{
    use RedirectsWithFlash;

    public function __construct(
        private GroupChatService $chat,
        private UserUsageLimitService $usageLimits,
        private PhotoService $photos,
        private LiveKitCallService $calls,
    ) {}

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;
        $workspace = $this->chat->listWorkspace($userId);

        return view('messages.workspace', $this->workspaceViewData($request, $workspace));
    }

    public function show(Request $request, int $groupId)
    {
        return $this->renderThread($request, $groupId, null);
    }

    public function showDm(Request $request, int $groupId, int $userId)
    {
        return $this->renderThread($request, $groupId, $userId);
    }

    public function callToken(Request $request, int $groupId, int $userId)
    {
        $user = $request->user();

        try {
            $this->chat->assertMember((int) $user->id, $groupId);
            $this->chat->assertPeer((int) $user->id, $groupId, $userId);

            if ($request->boolean('ring')) {
                $this->calls->ringDirectCall($user, $groupId, $userId);
            } else {
                // 着信側が応答したとき、自分宛の着信表示を消す
                $this->calls->clearIncoming((int) $user->id);
            }

            return response()->json([
                'ok' => true,
                ...$this->calls->tokenForDirectMessage($user, $groupId, $userId),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 403);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function callCancel(Request $request, int $groupId, int $userId)
    {
        $user = $request->user();

        try {
            $this->chat->assertMember((int) $user->id, $groupId);
            $this->chat->assertPeer((int) $user->id, $groupId, $userId);
            $this->calls->cancelDirectCall((int) $user->id, $groupId, $userId);

            return response()->json(['ok' => true]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 403);
        }
    }

    public function callDecline(Request $request)
    {
        $this->calls->declineIncomingCall((int) $request->user()->id);

        return response()->json(['ok' => true]);
    }

    public function store(Request $request, int $groupId)
    {
        $userId = (int) $request->user()->id;
        $peerId = $request->filled('peer_user_id') ? $request->integer('peer_user_id') : null;
        $replyToId = $request->filled('reply_to_id') ? $request->integer('reply_to_id') : null;
        $files = $request->file('attachments', []);
        if (! is_array($files)) {
            $files = $files ? [$files] : [];
        }

        $redirect = $peerId
            ? '/messages/'.$groupId.'/dm/'.$peerId
            : '/messages/'.$groupId;

        try {
            $message = $this->chat->send(
                $userId,
                $groupId,
                $request->input('body'),
                $files,
                $peerId,
                $replyToId
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }

            return $this->redirectWithMessage($redirect, $e->getMessage(), 'error');
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'message' => $message]);
        }

        return redirect($redirect);
    }

    public function poll(Request $request, int $groupId)
    {
        $userId = (int) $request->user()->id;
        $afterId = $request->integer('after');
        $peerId = $request->filled('peer') ? $request->integer('peer') : null;
        $since = $request->input('since');

        $this->chat->touchPresence($userId);

        try {
            $polled = $this->chat->pollThread(
                $userId,
                $groupId,
                $peerId,
                $afterId > 0 ? $afterId : 0,
                is_string($since) ? $since : null
            );
            $presence = $this->chat->presenceForGroup($groupId);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 403);
        }

        $payload = [
            'ok' => true,
            'messages' => $polled['messages'],
            'events' => $polled['events'],
            'presence' => $presence,
            'unreadCount' => (int) ($polled['unreadCount'] ?? 0),
            'incomingCall' => $this->calls->incomingForUser($userId),
            'callDeclined' => $this->calls->consumeDeclinedNotice($userId),
            'serverTime' => now()->toIso8601String(),
        ];

        // グループチャット背景のみ共有同期（DMは端末ローカル）
        if ($peerId === null) {
            try {
                $payload['wallpaper'] = $this->chat->wallpaperForGroup($userId, $groupId);
            } catch (\InvalidArgumentException) {
                // ignore
            }
        }

        return response()->json($payload);
    }

    /**
     * 会話未選択のメッセージ一覧でも、ユーザー宛の着信を確認する。
     */
    public function incomingCall(Request $request)
    {
        return response()->json([
            'ok' => true,
            'incomingCall' => $this->calls->incomingForUser((int) $request->user()->id),
        ]);
    }

    public function updateWallpaper(Request $request, int $groupId)
    {
        $userId = (int) $request->user()->id;

        try {
            if ($request->hasFile('wallpaper')) {
                $wallpaper = $this->chat->setGroupWallpaperImage(
                    $userId,
                    $groupId,
                    $request->file('wallpaper')
                );
            } elseif ($request->boolean('clear')) {
                $wallpaper = $this->chat->clearGroupWallpaper($userId, $groupId);
            } else {
                $request->validate(['theme' => 'required|string|max:32']);
                $wallpaper = $this->chat->setGroupWallpaperTheme(
                    $userId,
                    $groupId,
                    (string) $request->input('theme')
                );
            }
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'wallpaper' => $wallpaper]);
    }

    public function wallpaperFile(Request $request, int $groupId): StreamedResponse
    {
        try {
            $meta = $this->chat->streamGroupWallpaper((int) $request->user()->id, $groupId);
        } catch (\InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        }

        $disk = Storage::disk($meta['disk']);
        abort_unless($disk->exists($meta['path']), 404);

        return $disk->response($meta['path'], $meta['name'], [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function update(Request $request, int $id)
    {
        $request->validate(['body' => 'required|string|max:5000']);

        try {
            $message = $this->chat->edit((int) $request->user()->id, $id, (string) $request->input('body'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => $message]);
    }

    public function destroy(Request $request, int $id)
    {
        try {
            $result = $this->chat->deleteForUser((int) $request->user()->id, $id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, ...$result]);
    }

    public function react(Request $request, int $id)
    {
        $request->validate(['emoji' => 'required|string|max:16']);

        try {
            $message = $this->chat->toggleReaction(
                (int) $request->user()->id,
                $id,
                (string) $request->input('emoji')
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => $message]);
    }

    public function forward(Request $request, int $id)
    {
        $request->validate([
            'group_id' => 'required|integer',
            'peer_user_id' => 'nullable|integer',
        ]);

        $peerId = $request->filled('peer_user_id') ? $request->integer('peer_user_id') : null;

        try {
            $message = $this->chat->forward(
                (int) $request->user()->id,
                $id,
                $request->integer('group_id'),
                $peerId
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => $message]);
    }

    public function translate(Request $request, int $id, TranslationService $translator)
    {
        if (! $translator->isConfigured()) {
            return response()->json([
                'ok' => false,
                'message' => __('AI翻訳が設定されていません。'),
            ], 422);
        }

        try {
            $message = $this->chat->findAccessibleMessage((int) $request->user()->id, $id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 404);
        }

        $text = trim((string) ($message->body ?? ''));
        if ($text === '') {
            return response()->json(['ok' => false, 'message' => __('翻訳できる本文がありません。')], 422);
        }

        $target = in_array($request->input('target_lang'), ['ja', 'en'], true)
            ? $request->input('target_lang')
            : ($this->containsJapanese($text) ? 'en' : 'ja');
        $source = $target === 'en' ? 'ja' : 'en';

        try {
            $this->usageLimits->consume(
                $request->user(),
                UserUsageLimitService::FEATURE_TRANSLATE,
                mb_strlen($text)
            );
        } catch (UsageLimitExceededException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 429);
        }

        $translated = $translator->translate($text, $source, $target);
        if ($translated === null) {
            return response()->json(['ok' => false, 'message' => __('翻訳に失敗しました。')], 502);
        }

        return response()->json([
            'ok' => true,
            'target' => $target,
            'text' => $translated,
        ]);
    }

    public function attachmentFile(Request $request, int $id): StreamedResponse
    {
        return $this->streamAttachment($request, $id, false);
    }

    public function attachmentDownload(Request $request, int $id): StreamedResponse
    {
        return $this->streamAttachment($request, $id, true);
    }

    public function attachmentSaveToPhotos(Request $request, int $id)
    {
        $userId = (int) $request->user()->id;
        $attachment = $this->chat->findAccessibleAttachment($userId, $id);
        abort_unless($attachment, 404);

        if (! $this->photos->canImportMimeAndName($attachment->mime, $attachment->original_name)) {
            return response()->json([
                'ok' => false,
                'message' => __('Photosに追加できない形式です。'),
            ], 422);
        }

        $albumId = $request->filled('album_id') ? $request->integer('album_id') : null;

        try {
            $result = $this->photos->importFromStoredFile(
                $userId,
                (string) $attachment->disk,
                (string) $attachment->path,
                (string) ($attachment->original_name ?: 'message-attachment'),
                $attachment->mime,
                $albumId > 0 ? $albumId : null,
            );
        } catch (UsageLimitExceededException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        if (($result['created'] ?? []) === [] && ($result['skipped'] ?? []) !== []) {
            return response()->json([
                'ok' => true,
                'skipped' => true,
                'message' => __('すでに Photos に同じ写真があります。'),
                'photosUrl' => '/photos',
            ]);
        }

        return response()->json([
            'ok' => true,
            'skipped' => false,
            'photo' => $result['created'][0] ?? null,
            'message' => __('Photosに追加しました。'),
            'photosUrl' => '/photos',
        ]);
    }

    private function containsJapanese(string $text): bool
    {
        return (bool) preg_match('/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $text);
    }

    private function renderThread(Request $request, int $groupId, ?int $peerUserId)
    {
        $userId = (int) $request->user()->id;
        $this->chat->touchPresence($userId);
        $workspace = $this->chat->listWorkspace($userId);

        try {
            $group = $this->chat->assertMember($userId, $groupId);
            $peer = $peerUserId !== null
                ? $this->chat->assertPeer($userId, $groupId, $peerUserId)
                : null;
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage('/messages', $e->getMessage(), 'error');
        }

        $messages = $this->chat->listMessages($userId, $groupId, $peerUserId);
        $firstUnreadMessageId = null;
        $latestMessageId = 0;
        foreach ($messages as $message) {
            $id = (int) ($message['id'] ?? 0);
            $latestMessageId = max($latestMessageId, $id);
            if ($firstUnreadMessageId === null && ! empty($message['isUnread'])) {
                $firstUnreadMessageId = $id;
            }
        }
        if ($latestMessageId > 0) {
            $this->chat->markThreadRead($userId, $groupId, $peerUserId, $latestMessageId);
        }

        $activeHref = $peerUserId
            ? '/messages/'.$groupId.'/dm/'.$peerUserId
            : '/messages/'.$groupId;

        $forwardTargets = [];
        foreach ($workspace as $room) {
            $forwardTargets[] = [
                'groupId' => $room['id'],
                'peerUserId' => null,
                'label' => __('グループチャット').' · '.$room['name'],
                'href' => $room['href'],
            ];
            foreach ($room['members'] as $member) {
                $forwardTargets[] = [
                    'groupId' => $room['id'],
                    'peerUserId' => $member['userId'],
                    'label' => __('個別チャット').' · '.$member['displayName'].' ('.$room['name'].')',
                    'href' => $member['href'],
                ];
            }
        }

        return view('messages.workspace', [
            ...$this->workspaceViewData($request, $workspace),
            'activeGroup' => $group->toPublicArray(),
            'activePeer' => $peer ? [
                'userId' => $peer->id,
                'displayName' => $peer->display_name,
                'initials' => mb_substr((string) $peer->display_name, 0, 1),
                'online' => $peer->isOnline(GroupChatService::ONLINE_WITHIN_SECONDS),
                'lastSeenAt' => $peer->last_seen_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
            ] : null,
            'activeHref' => $activeHref,
            'messages' => $messages,
            'firstUnreadMessageId' => $firstUnreadMessageId,
            'isDirect' => $peerUserId !== null,
            'forwardTargets' => $forwardTargets,
            'reactionEmojis' => GroupChatService::REACTION_EMOJIS,
            'composeEmojis' => ['😀', '😂', '😍', '🥰', '😎', '🤔', '👍', '👎', '👏', '🙏', '❤️', '🔥', '🎉', '✨', '😢', '😮'],
            'canTranslate' => app(TranslationService::class)->isConfigured(),
            'wallpaper' => $peerUserId === null
                ? $this->chat->wallpaperToArray($group)
                : null,
        ]);
    }

    /** @param list<array<string, mixed>> $workspace */
    private function workspaceViewData(Request $request, array $workspace): array
    {
        $userId = (int) ($request->user()->id ?? 0);

        return [
            'workspace' => $workspace,
            'inboxRecentMessages' => $userId > 0
                ? $this->chat->inboxRecentGroupMessages($userId, 20)
                : [],
            'activeGroup' => null,
            'activePeer' => null,
            'activeHref' => null,
            'messages' => [],
            'firstUnreadMessageId' => null,
            'isDirect' => false,
            'forwardTargets' => [],
            'reactionEmojis' => GroupChatService::REACTION_EMOJIS,
            'composeEmojis' => ['😀', '😂', '😍', '🥰', '😎', '🤔', '👍', '👎', '👏', '🙏', '❤️', '🔥', '🎉', '✨', '😢', '😮'],
            'canTranslate' => false,
            'maxUploadLabel' => $this->formatBytes($this->chat->maxAttachmentBytes()),
            'maxAttachmentBytes' => $this->chat->maxAttachmentBytes(),
            ...$this->flashFromQuery($request),
        ];
    }

    private function streamAttachment(Request $request, int $id, bool $download): StreamedResponse
    {
        $attachment = $this->chat->findAccessibleAttachment((int) $request->user()->id, $id);
        abort_unless($attachment, 404);

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        $headers = SafeAttachmentResponse::headers(
            $attachment->mime,
            (string) $attachment->original_name,
            $download
        );
        $asDownload = str_starts_with($headers['Content-Disposition'], 'attachment');

        if ($asDownload) {
            return $disk->download($attachment->path, $attachment->original_name, $headers);
        }

        return $disk->response($attachment->path, $attachment->original_name, $headers);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1048576) {
            return round($bytes / 1024).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }
}
