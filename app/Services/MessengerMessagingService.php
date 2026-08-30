<?php

namespace App\Services;

use App\Exceptions\UsageLimitExceededException;
use App\Models\MessagingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MessengerMessagingService
{
    public function __construct(
        private MessagingLinkService $links,
        private FacebookMessagingConfigService $config,
    ) {}

    public function isConfigured(): bool
    {
        return $this->config->isReady();
    }

    public function pageAccessToken(): string
    {
        return $this->config->pageAccessToken();
    }

    public function appSecret(): string
    {
        return $this->config->appSecret();
    }

    public function verifyToken(): string
    {
        return $this->config->verifyToken();
    }

    public function pageName(): string
    {
        return $this->config->pageName();
    }

    public function verifyWebhookChallenge(?string $mode, ?string $token, ?string $challenge): ?string
    {
        $expected = $this->verifyToken();
        if ($expected === '' || $mode !== 'subscribe' || $token === null || $challenge === null) {
            return null;
        }
        if (! hash_equals($expected, $token)) {
            return null;
        }

        return $challenge;
    }

    public function verifySignature(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = $this->appSecret();
        if ($secret === '' || $signatureHeader === null || $signatureHeader === '') {
            return false;
        }

        if (! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $incoming = substr($signatureHeader, 7);
        $digest = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($digest, $incoming);
    }

    /**
     * @return array<string, mixed>
     */
    public function formState(?User $user): array
    {
        $connection = $user ? $this->links->connectionFor($user, MessagingConnection::PROVIDER_MESSENGER) : null;
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
            return ['ok' => false, 'message' => 'Facebook Messenger が未設定です。'];
        }

        $connection = $this->links->connectionFor($user, MessagingConnection::PROVIDER_MESSENGER);
        if (! $connection) {
            return ['ok' => false, 'message' => 'Messenger が未連携です。'];
        }

        try {
            app(UserUsageLimitService::class)->assertWithin($user, UserUsageLimitService::FEATURE_NOTIFY, 1);
        } catch (UsageLimitExceededException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $result = $this->pushText($connection->external_user_id, $text);
        if (! empty($result['ok'])) {
            try {
                app(UserUsageLimitService::class)->consume($user, UserUsageLimitService::FEATURE_NOTIFY, 1);
            } catch (UsageLimitExceededException) {
                // 送信済みのため失敗しても止めない
            }
        }

        return $result;
    }

    public function pushText(string $psid, string $text): array
    {
        $response = Http::acceptJson()
            ->asJson()
            ->withQueryParameters(['access_token' => $this->pageAccessToken()])
            ->post('https://graph.facebook.com/v21.0/me/messages', [
                'recipient' => ['id' => $psid],
                'messaging_type' => 'UPDATE',
                'message' => ['text' => mb_substr($text, 0, 1900)],
            ]);

        if (! $response->successful()) {
            Log::warning('Messenger push failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'ok' => false,
                'message' => 'Messenger 送信に失敗しました（HTTP '.$response->status().'）。ページへメッセージを送ったことがあるか確認してください。',
            ];
        }

        app(IntegrationUsageService::class)->increment('facebook', 'messages');

        return ['ok' => true, 'message' => 'sent'];
    }
}
