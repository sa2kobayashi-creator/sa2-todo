<?php

namespace App\Http\Controllers;

use App\Models\MessagingConnection;
use App\Services\MessagingLinkService;
use App\Services\MessengerMessagingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MessengerWebhookController extends Controller
{
    public function __construct(
        private MessengerMessagingService $messenger,
        private MessagingLinkService $links,
    ) {}

    public function verify(Request $request)
    {
        $challenge = $this->messenger->verifyWebhookChallenge(
            $request->query('hub.mode', $request->query('hub_mode')),
            $request->query('hub.verify_token', $request->query('hub_verify_token')),
            $request->query('hub.challenge', $request->query('hub_challenge'))
        );

        if ($challenge === null) {
            return response('forbidden', 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request)
    {
        $raw = $request->getContent();
        if (! $this->messenger->verifySignature($raw, $request->header('X-Hub-Signature-256'))) {
            Log::warning('Messenger webhook signature mismatch');

            return response('invalid signature', 403);
        }

        if (! $this->messenger->isConfigured()) {
            return response('not configured', 503);
        }

        $payload = json_decode($raw, true);
        $entries = is_array($payload['entry'] ?? null) ? $payload['entry'] : [];

        foreach ($entries as $entry) {
            $messaging = is_array($entry['messaging'] ?? null) ? $entry['messaging'] : [];
            foreach ($messaging as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $sender = is_array($item['sender'] ?? null) ? $item['sender'] : [];
                $psid = (string) ($sender['id'] ?? '');
                $message = is_array($item['message'] ?? null) ? $item['message'] : [];
                $text = trim((string) ($message['text'] ?? ''));
                if ($psid === '' || $text === '' || ! empty($message['is_echo'])) {
                    continue;
                }

                if (! preg_match('/\b([A-Za-z0-9]{6})\b/', $text, $m)) {
                    $this->messenger->pushText(
                        $psid,
                        '連携するには、'.config('app.name').' マイページの6桁コードを送ってください。'
                    );
                    continue;
                }

                $result = $this->links->consumeCode(
                    MessagingConnection::PROVIDER_MESSENGER,
                    $m[1],
                    $psid,
                    null
                );

                if (! empty($result['ok'])) {
                    $this->messenger->pushText($psid, config('app.name').' と連携しました。マイページからテスト通知を送れます。');
                } else {
                    $this->messenger->pushText($psid, '連携コードが無効か期限切れです。設定画面で再発行してください。');
                }
            }
        }

        return response('EVENT_RECEIVED', 200);
    }
}
