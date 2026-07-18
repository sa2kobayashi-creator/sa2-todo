<?php

namespace App\Http\Controllers;

use App\Services\AiChatService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiChatController extends Controller
{
    public function __construct(private AiChatService $chat) {}

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;
        $conversationId = $request->query('c') !== null && $request->query('c') !== ''
            ? (int) $request->query('c')
            : null;

        $conversations = $this->chat->listConversations($userId);
        $active = null;
        if ($conversationId) {
            try {
                $active = $this->chat->getConversation($userId, $conversationId);
            } catch (\InvalidArgumentException) {
                $active = null;
            }
        }

        return view('ai-chat.index', [
            'conversations' => $conversations,
            'activeConversation' => $active,
            'providers' => $this->chat->providerOptions(),
            'hasApiKey' => $this->chat->hasAnyActiveKey(),
        ]);
    }

    public function store(Request $request)
    {
        $userId = (int) $request->user()->id;
        try {
            $conversation = $this->chat->createConversation(
                $userId,
                (string) $request->input('provider', 'openai'),
                $request->input('model')
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'provider' => $conversation->provider,
                'model' => $conversation->model,
            ],
        ]);
    }

    public function show(Request $request, int $id)
    {
        try {
            $conversation = $this->chat->getConversation((int) $request->user()->id, $id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 404);
        }

        return response()->json(['ok' => true, 'conversation' => $conversation]);
    }

    public function destroy(Request $request, int $id)
    {
        if (! $this->chat->deleteConversation((int) $request->user()->id, $id)) {
            return response()->json(['ok' => false, 'message' => '会話が見つかりません'], 404);
        }

        return response()->json(['ok' => true]);
    }

    public function stream(Request $request, int $id): StreamedResponse
    {
        $userId = (int) $request->user()->id;
        $message = (string) $request->input('message', '');

        return response()->stream(function () use ($userId, $id, $message) {
            $send = function (array $payload) {
                echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                @flush();
            };

            try {
                $assistant = $this->chat->streamReply($userId, $id, $message, function (string $delta) use ($send) {
                    $send(['type' => 'delta', 'text' => $delta]);
                });
                $conversation = $this->chat->getConversation($userId, $id);
                $send([
                    'type' => 'done',
                    'message' => [
                        'id' => $assistant->id,
                        'role' => 'assistant',
                        'content' => $assistant->content,
                    ],
                    'conversation' => [
                        'id' => $conversation['id'],
                        'title' => $conversation['title'],
                        'provider' => $conversation['provider'],
                        'model' => $conversation['model'],
                    ],
                ]);
            } catch (\InvalidArgumentException $e) {
                $send(['type' => 'error', 'message' => $e->getMessage()]);
            } catch (\Throwable $e) {
                report($e);
                $send(['type' => 'error', 'message' => $e->getMessage() ?: '応答の取得に失敗しました']);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }
}
