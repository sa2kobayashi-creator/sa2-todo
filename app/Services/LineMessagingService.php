<?php

namespace App\Services;

use App\Models\MessagingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LineMessagingService
{
    public function __construct(
        private MessagingLinkService $links,
        private LineConfigService $config,
    ) {}

    public function isConfigured(): bool
    {
        return $this->config->isReady();
    }

    public function channelAccessToken(): string
    {
        return $this->config->channelAccessToken();
    }

    public function channelSecret(): string
    {
        return $this->config->channelSecret();
    }

    public function botBasicId(): string
    {
        return $this->config->botBasicId();
    }

    public function verifySignature(string $rawBody, ?string $signature): bool
    {
        $secret = $this->channelSecret();
        if ($secret === '' || $signature === null || $signature === '') {
            return false;
        }

        $digest = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return hash_equals($digest, $signature);
    }

    /**
     * @return array<string, mixed>
     */
    public function formState(?User $user): array
    {
        $connection = $user ? $this->links->connectionFor($user, MessagingConnection::PROVIDER_LINE) : null;
        $channel = $this->config->formState();

        return array_merge($channel, [
            'configured' => $this->isConfigured(),
            'connected' => $connection !== null,
            'displayName' => $connection?->display_name,
            'linkedAt' => $connection?->linked_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
        ]);
    }

    public function sendText(User $user, string $text): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'message' => 'LINE Messaging API が未設定です。'];
        }

        $connection = $this->links->connectionFor($user, MessagingConnection::PROVIDER_LINE);
        if (! $connection) {
            return ['ok' => false, 'message' => 'LINE が未連携です。'];
        }

        return $this->pushText($connection->external_user_id, $text);
    }

    public function pushText(string $lineUserId, string $text): array
    {
        $response = Http::withToken($this->channelAccessToken())
            ->acceptJson()
            ->asJson()
            ->post('https://api.line.me/v2/bot/message/push', [
                'to' => $lineUserId,
                'messages' => [
                    [
                        'type' => 'text',
                        'text' => mb_substr($text, 0, 4900),
                    ],
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('LINE push failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'ok' => false,
                'message' => 'LINE 送信に失敗しました（HTTP '.$response->status().'）。公式アカウントを友だち追加しているか確認してください。',
            ];
        }

        app(IntegrationUsageService::class)->increment('line', 'messages');

        return ['ok' => true, 'message' => 'sent'];
    }

    public function replyText(string $replyToken, string $text): void
    {
        if (! $this->isConfigured() || $replyToken === '') {
            return;
        }

        Http::withToken($this->channelAccessToken())
            ->acceptJson()
            ->asJson()
            ->post('https://api.line.me/v2/bot/message/reply', [
                'replyToken' => $replyToken,
                'messages' => [
                    [
                        'type' => 'text',
                        'text' => mb_substr($text, 0, 4900),
                    ],
                ],
            ]);
    }
}
