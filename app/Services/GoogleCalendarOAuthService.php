<?php

namespace App\Services;

use App\Models\GoogleCalendarConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleCalendarOAuthService
{
    public const SESSION_STATE_KEY = 'google_calendar_oauth_state';

    public function __construct(private GoogleCalendarConfigService $config) {}

    /** @return list<string> */
    public function scopes(): array
    {
        return [
            'openid',
            'email',
            'https://www.googleapis.com/auth/calendar.events',
            'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
        ];
    }

    public function isConfigured(): bool
    {
        return $this->config->isReady();
    }

    public function clientId(): string
    {
        return $this->config->clientId();
    }

    public function redirectUri(): string
    {
        return $this->config->redirectUri();
    }

    private function clientSecret(): string
    {
        return $this->config->clientSecret();
    }

    /**
     * @return array{url: string, state: string}
     */
    public function beginAuthorization(): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Google Calendar OAuth が設定されていません。設定 → API設定 で Client ID を登録してください。');
        }

        $state = Str::random(40);
        session([self::SESSION_STATE_KEY => $state]);

        $query = http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes()),
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return [
            'url' => 'https://accounts.google.com/o/oauth2/v2/auth?'.$query,
            'state' => $state,
        ];
    }

    public function validateState(?string $state): bool
    {
        $expected = session()->pull(self::SESSION_STATE_KEY);
        if (! is_string($expected) || $expected === '' || ! is_string($state) || $state === '') {
            return false;
        }

        return hash_equals($expected, $state);
    }

    /**
     * @return array{
     *   access_token: string,
     *   refresh_token: ?string,
     *   expires_in: int,
     *   scope: string,
     *   token_type: string,
     *   id_token?: string
     * }
     */
    public function exchangeAuthorizationCode(string $code): array
    {
        $response = Http::asForm()
            ->timeout(20)
            ->post('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'redirect_uri' => $this->redirectUri(),
                'grant_type' => 'authorization_code',
            ]);

        if (! $response->successful()) {
            Log::warning('Google Calendar OAuth token exchange failed', [
                'status' => $response->status(),
            ]);
            throw new \RuntimeException('Google からのトークン取得に失敗しました。');
        }

        $data = $response->json();
        if (! is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException('Google からのトークン応答が不正です。');
        }

        return [
            'access_token' => (string) $data['access_token'],
            'refresh_token' => isset($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            'expires_in' => (int) ($data['expires_in'] ?? 3600),
            'scope' => (string) ($data['scope'] ?? implode(' ', $this->scopes())),
            'token_type' => (string) ($data['token_type'] ?? 'Bearer'),
            'id_token' => isset($data['id_token']) ? (string) $data['id_token'] : null,
        ];
    }

    /**
     * @return array{sub: string, email: ?string}
     */
    public function fetchGoogleUser(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->timeout(15)
            ->acceptJson()
            ->get('https://openidconnect.googleapis.com/v1/userinfo');

        if (! $response->successful()) {
            Log::warning('Google Calendar OAuth userinfo failed', [
                'status' => $response->status(),
            ]);
            throw new \RuntimeException('Google アカウント情報の取得に失敗しました。');
        }

        $data = $response->json();
        $sub = is_array($data) ? trim((string) ($data['sub'] ?? '')) : '';
        if ($sub === '') {
            throw new \RuntimeException('Google ユーザー ID（sub）を取得できませんでした。');
        }

        $email = is_array($data) && ! empty($data['email']) ? (string) $data['email'] : null;

        return [
            'sub' => $sub,
            'email' => $email,
        ];
    }

    public function saveConnection(User $user, array $token, array $googleUser): GoogleCalendarConnection
    {
        $existingByGoogle = GoogleCalendarConnection::query()
            ->where('google_user_id', $googleUser['sub'])
            ->where('user_id', '!=', $user->id)
            ->first();
        if ($existingByGoogle) {
            throw new \RuntimeException('この Google アカウントは別のユーザーに既に連携されています。');
        }

        $connection = GoogleCalendarConnection::query()->firstOrNew(['user_id' => $user->id]);
        $refresh = $token['refresh_token'] ?? null;
        if ((! is_string($refresh) || $refresh === '') && $connection->exists) {
            // 再連携で refresh_token が返らない場合は既存を維持
            $refresh = $connection->refresh_token;
        }

        $connection->fill([
            'google_user_id' => $googleUser['sub'],
            'google_email' => $googleUser['email'],
            'access_token' => $token['access_token'],
            'refresh_token' => is_string($refresh) && $refresh !== '' ? $refresh : null,
            'token_expires_at' => now()->addSeconds(max(60, (int) ($token['expires_in'] ?? 3600))),
            'scopes' => (string) ($token['scope'] ?? implode(' ', $this->scopes())),
        ]);
        $connection->save();

        return $connection->fresh();
    }

    public function refreshAccessToken(GoogleCalendarConnection $connection): GoogleCalendarConnection
    {
        $refresh = $connection->refresh_token;
        if (! is_string($refresh) || $refresh === '') {
            throw new \RuntimeException('refresh token がありません。Google カレンダーを再連携してください。');
        }

        $response = Http::asForm()
            ->timeout(20)
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'refresh_token' => $refresh,
                'grant_type' => 'refresh_token',
            ]);

        if (! $response->successful()) {
            Log::warning('Google Calendar OAuth refresh failed', [
                'user_id' => $connection->user_id,
                'status' => $response->status(),
            ]);
            throw new \RuntimeException('アクセストークンの更新に失敗しました。再連携してください。');
        }

        $data = $response->json();
        if (! is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException('トークン更新の応答が不正です。');
        }

        $connection->access_token = (string) $data['access_token'];
        $connection->token_expires_at = now()->addSeconds(max(60, (int) ($data['expires_in'] ?? 3600)));
        if (! empty($data['scope'])) {
            $connection->scopes = (string) $data['scope'];
        }
        $connection->save();

        return $connection->fresh();
    }

    public function revokeAndDelete(GoogleCalendarConnection $connection): void
    {
        $token = $connection->refresh_token ?: $connection->access_token;
        if (is_string($token) && $token !== '') {
            try {
                Http::asForm()
                    ->timeout(15)
                    ->post('https://oauth2.googleapis.com/revoke', [
                        'token' => $token,
                    ]);
            } catch (\Throwable $e) {
                Log::warning('Google Calendar OAuth revoke request failed', [
                    'user_id' => $connection->user_id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $connection->delete();
    }
}
