<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Services\GroupChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageController extends Controller
{
    use RedirectsWithFlash;

    public function __construct(private GroupChatService $chat) {}

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;
        $workspace = $this->chat->listWorkspace($userId);
        if ($workspace !== []) {
            return redirect($workspace[0]['href']);
        }

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

    public function store(Request $request, int $groupId)
    {
        $userId = (int) $request->user()->id;
        $peerId = $request->filled('peer_user_id') ? $request->integer('peer_user_id') : null;
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
                $peerId
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

        try {
            $messages = $this->chat->listMessages(
                $userId,
                $groupId,
                $peerId,
                $afterId > 0 ? $afterId : null
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 403);
        }

        return response()->json(['ok' => true, 'messages' => $messages]);
    }

    public function attachmentFile(Request $request, int $id): StreamedResponse
    {
        return $this->streamAttachment($request, $id, false);
    }

    public function attachmentDownload(Request $request, int $id): StreamedResponse
    {
        return $this->streamAttachment($request, $id, true);
    }

    private function renderThread(Request $request, int $groupId, ?int $peerUserId)
    {
        $userId = (int) $request->user()->id;
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
        $activeHref = $peerUserId
            ? '/messages/'.$groupId.'/dm/'.$peerUserId
            : '/messages/'.$groupId;

        return view('messages.workspace', [
            ...$this->workspaceViewData($request, $workspace),
            'activeGroup' => $group->toPublicArray(),
            'activePeer' => $peer ? [
                'userId' => $peer->id,
                'displayName' => $peer->display_name,
                'initials' => mb_substr((string) $peer->display_name, 0, 1),
            ] : null,
            'activeHref' => $activeHref,
            'messages' => $messages,
            'isDirect' => $peerUserId !== null,
        ]);
    }

    /** @param list<array<string, mixed>> $workspace */
    private function workspaceViewData(Request $request, array $workspace): array
    {
        return [
            'workspace' => $workspace,
            'activeGroup' => null,
            'activePeer' => null,
            'activeHref' => null,
            'messages' => [],
            'isDirect' => false,
            'maxUploadLabel' => $this->formatBytes($this->chat->maxAttachmentBytes()),
            ...$this->flashFromQuery($request),
        ];
    }

    private function streamAttachment(Request $request, int $id, bool $download): StreamedResponse
    {
        $attachment = $this->chat->findAccessibleAttachment((int) $request->user()->id, $id);
        abort_unless($attachment, 404);

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        $headers = [
            'Content-Type' => $attachment->mime ?: 'application/octet-stream',
        ];

        if ($download) {
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
