<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function publicKey(WebPushService $push): JsonResponse
    {
        $key = $push->publicKey();

        if ($key === null) {
            return response()->json(['ok' => false, 'message' => __('プッシュ通知はまだ設定されていません。')], 503);
        }

        return response()->json(['ok' => true, 'publicKey' => $key]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:4096'],
            'expirationTime' => ['nullable'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:1024'],
            'keys.auth' => ['required', 'string', 'max:1024'],
        ]);

        $expiresAt = is_numeric($data['expirationTime'] ?? null)
            ? now()->setTimestamp((int) floor(((float) $data['expirationTime']) / 1000))
            : null;

        PushSubscription::query()->updateOrCreate(
            [
                'user_id' => (int) $request->user()->id,
                'endpoint_hash' => hash('sha256', $data['endpoint']),
            ],
            [
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => 'aes128gcm',
                'expires_at' => $expiresAt,
                'last_used_at' => now(),
            ],
        );

        return response()->json(['ok' => true], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:4096'],
        ]);

        PushSubscription::query()
            ->where('user_id', (int) $request->user()->id)
            ->where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->delete();

        return response()->json(['ok' => true]);
    }
}
