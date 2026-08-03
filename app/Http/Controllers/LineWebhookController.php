<?php

namespace App\Http\Controllers;

use App\Models\MessagingConnection;
use App\Services\LineMessagingService;
use App\Services\MessagingLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LineWebhookController extends Controller
{
    public function __construct(
        private LineMessagingService $line,
        private MessagingLinkService $links,
    ) {}

    public function __invoke(Request $request)
    {
        $raw = $request->getContent();
        if (! $this->line->verifySignature($raw, $request->header('X-Line-Signature'))) {
            Log::warning('LINE webhook signature mismatch');

            return response('invalid signature', 403);
        }

        if (! $this->line->isConfigured()) {
            return response('not configured', 503);
        }

        $payload = json_decode($raw, true);
        $events = is_array($payload['events'] ?? null) ? $payload['events'] : [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            $type = (string) ($event['type'] ?? '');
            $replyToken = (string) ($event['replyToken'] ?? '');
            $source = is_array($event['source'] ?? null) ? $event['source'] : [];
            $lineUserId = (string) ($source['userId'] ?? '');

            if ($type === 'follow' && $lineUserId !== '') {
                $this->line->replyText(
                    $replyToken,
                    "友だち追加ありがとうございます。\nSa2 Studio の設定画面で発行した連携コードをこのトークに送信してください。"
                );
                continue;
            }

            if ($type !== 'message' || $lineUserId === '') {
                continue;
            }

            $message = is_array($event['message'] ?? null) ? $event['message'] : [];
            if (($message['type'] ?? '') !== 'text') {
                continue;
            }

            $text = trim((string) ($message['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            // 先頭の英数字6桁をコードとして扱う
            if (! preg_match('/\b([A-Za-z0-9]{6})\b/', $text, $m)) {
                $this->line->replyText(
                    $replyToken,
                    "連携するには、設定画面の6桁コードを送ってください。"
                );
                continue;
            }

            $result = $this->links->consumeCode(
                MessagingConnection::PROVIDER_LINE,
                $m[1],
                $lineUserId,
                null
            );

            if (! empty($result['ok'])) {
                $this->line->replyText($replyToken, "Sa2 Studio と連携しました。テスト通知を設定画面から送れます。");
            } else {
                $this->line->replyText($replyToken, "連携コードが無効か期限切れです。設定画面で再発行してください。");
            }
        }

        return response('ok', 200);
    }
}
