<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class WebPushService
{
    public function isConfigured(): bool
    {
        $config = config('services.web_push');

        return filled($config['public_key'] ?? null)
            && filled($config['private_key'] ?? null)
            && filled($config['subject'] ?? null);
    }

    public function publicKey(): ?string
    {
        return $this->isConfigured()
            ? (string) config('services.web_push.public_key')
            : null;
    }

    /**
     * 失敗しても通話の発信自体は止めない。
     *
     * @param  array{title: string, body: string, url: string, tag: string}  $payload
     */
    public function notify(User $user, array $payload): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $subscriptions = PushSubscription::query()
            ->where('user_id', $user->id)
            ->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => (string) config('services.web_push.subject'),
                    'publicKey' => (string) config('services.web_push.public_key'),
                    'privateKey' => (string) config('services.web_push.private_key'),
                ],
            ]);

            $message = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($message === false) {
                return;
            }

            foreach ($subscriptions as $subscription) {
                try {
                $report = $webPush->sendOneNotification(
                    Subscription::create([
                        'endpoint' => $subscription->endpoint,
                        'publicKey' => $subscription->public_key,
                        'authToken' => $subscription->auth_token,
                        'contentEncoding' => $subscription->content_encoding,
                    ]),
                    $message,
                    [
                        'TTL' => 90,
                        'urgency' => 'high',
                        'topic' => substr($payload['tag'], 0, 32),
                    ],
                );

                if ($report->isSuccess()) {
                    $subscription->forceFill(['last_used_at' => now()])->save();
                    continue;
                }

                if ($report->isSubscriptionExpired()) {
                    $subscription->delete();
                    continue;
                }

                Log::warning('Web Push delivery failed.', [
                    'user_id' => $user->id,
                    'endpoint_hash' => $subscription->endpoint_hash,
                    'reason' => $report->getReason(),
                ]);
                } catch (Throwable $e) {
                    Log::warning('Web Push delivery errored.', [
                        'user_id' => $user->id,
                        'endpoint_hash' => $subscription->endpoint_hash,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Web Push initialization failed.', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
