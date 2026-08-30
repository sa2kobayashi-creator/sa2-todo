<?php

namespace App\Services;

use App\Exceptions\UsageLimitExceededException;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class WebPushService
{
    public function __construct(private WebPushConfigService $config) {}

    public function isConfigured(): bool
    {
        return $this->config->isReady();
    }

    public function publicKey(): ?string
    {
        $key = $this->config->publicKey();

        return $key !== '' ? $key : null;
    }

    /**
     * 失敗しても元の操作は止めない。
     *
     * @param  array{title: string, body: string, url: string, tag: string, ttl?: int, urgency?: string}  $payload
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
            app(UserUsageLimitService::class)->assertWithin($user, UserUsageLimitService::FEATURE_NOTIFY, 1);
        } catch (UsageLimitExceededException) {
            return;
        }

        $delivered = false;

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $this->config->subject(),
                    'publicKey' => $this->config->publicKey(),
                    'privateKey' => $this->config->privateKey(),
                ],
            ]);

            $message = json_encode([
                'title' => $payload['title'],
                'body' => $payload['body'],
                'url' => $payload['url'],
                'tag' => $payload['tag'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($message === false) {
                return;
            }

            $ttl = max(1, (int) ($payload['ttl'] ?? 90));
            $urgency = (string) ($payload['urgency'] ?? 'high');
            if (! in_array($urgency, ['very-low', 'low', 'normal', 'high'], true)) {
                $urgency = 'high';
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
                            'TTL' => $ttl,
                            'urgency' => $urgency,
                            'topic' => substr($payload['tag'], 0, 32),
                        ],
                    );

                    if ($report->isSuccess()) {
                        $subscription->forceFill(['last_used_at' => now()])->save();
                        app(IntegrationUsageService::class)->increment('web_push', 'deliveries');
                        $delivered = true;
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

        if ($delivered) {
            try {
                app(UserUsageLimitService::class)->consume($user, UserUsageLimitService::FEATURE_NOTIFY, 1);
            } catch (UsageLimitExceededException) {
                // 配信済みのため失敗しても止めない
            }
        }
    }
}
