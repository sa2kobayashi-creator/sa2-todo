<?php

namespace App\Services;

use App\Models\MessagingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MessagingLinkService
{
    private const CODE_TTL_MINUTES = 15;

    public function cacheKey(string $code): string
    {
        return 'messaging_link:'.strtoupper(trim($code));
    }

    public function issueCode(User $user, string $provider): string
    {
        $this->assertProvider($provider);

        // 同一ユーザー・同一プロバイダの未使用コードを上書きしやすいよう短い英数字
        $code = strtoupper(Str::random(6));
        Cache::put($this->cacheKey($code), [
            'user_id' => $user->id,
            'provider' => $provider,
        ], now()->addMinutes(self::CODE_TTL_MINUTES));

        return $code;
    }

    /**
     * @return array{ok: bool, message: string, connection?: MessagingConnection}
     */
    public function consumeCode(string $provider, string $rawCode, string $externalUserId, ?string $displayName = null): array
    {
        $this->assertProvider($provider);
        $code = strtoupper(trim($rawCode));
        if ($code === '' || strlen($code) < 4) {
            return ['ok' => false, 'message' => 'invalid_code'];
        }

        $payload = Cache::pull($this->cacheKey($code));
        if (! is_array($payload) || (int) ($payload['user_id'] ?? 0) <= 0) {
            return ['ok' => false, 'message' => 'code_expired'];
        }
        if (($payload['provider'] ?? '') !== $provider) {
            return ['ok' => false, 'message' => 'provider_mismatch'];
        }

        $user = User::find((int) $payload['user_id']);
        if (! $user) {
            return ['ok' => false, 'message' => 'user_missing'];
        }

        // 同じ外部 ID が別ユーザーに付いていたら外す
        MessagingConnection::query()
            ->where('provider', $provider)
            ->where('external_user_id', $externalUserId)
            ->where('user_id', '!=', $user->id)
            ->delete();

        $connection = MessagingConnection::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'provider' => $provider,
            ],
            [
                'external_user_id' => $externalUserId,
                'display_name' => $displayName,
                'linked_at' => now(),
                'meta' => [
                    'linked_via' => 'code',
                    'code' => $code,
                ],
            ]
        );

        Log::info('Messaging account linked', [
            'provider' => $provider,
            'user_id' => $user->id,
            'external_user_id' => $externalUserId,
        ]);

        return [
            'ok' => true,
            'message' => 'linked',
            'connection' => $connection,
        ];
    }

    public function connectionFor(User $user, string $provider): ?MessagingConnection
    {
        $this->assertProvider($provider);

        return MessagingConnection::query()
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->first();
    }

    public function disconnect(User $user, string $provider): void
    {
        $this->assertProvider($provider);
        MessagingConnection::query()
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->delete();
    }

    private function assertProvider(string $provider): void
    {
        if (! in_array($provider, [MessagingConnection::PROVIDER_LINE, MessagingConnection::PROVIDER_MESSENGER], true)) {
            throw new \InvalidArgumentException('Unknown messaging provider: '.$provider);
        }
    }
}
