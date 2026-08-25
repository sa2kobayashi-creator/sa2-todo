<?php

namespace App\Http\Controllers;

use App\Models\MessagingConnection;
use App\Services\FacebookMessagingConfigService;
use App\Services\LineConfigService;
use App\Services\LineMessagingService;
use App\Services\MessagingLinkService;
use App\Services\MessengerMessagingService;
use App\Services\ReminderNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessagingSettingsController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(
        private MessagingLinkService $links,
        private LineMessagingService $line,
        private MessengerMessagingService $messenger,
        private LineConfigService $lineConfig,
        private FacebookMessagingConfigService $facebookConfig,
        private ReminderNotificationService $reminders,
    ) {}

    public function saveChannel(Request $request, string $provider)
    {
        $provider = $this->normalizeProvider($provider);
        $path = $this->settingsPath($provider);

        if ($provider === MessagingConnection::PROVIDER_LINE) {
            $request->validate([
                'channel_access_token' => ['nullable', 'string', 'max:2000'],
                'channel_secret' => ['nullable', 'string', 'max:500'],
                'bot_basic_id' => ['nullable', 'string', 'max:64'],
                'qr_code' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            ]);

            $this->lineConfig->saveConfig(
                $request->boolean('enabled'),
                [
                    'channel_access_token' => (string) $request->input('channel_access_token', ''),
                    'channel_secret' => (string) $request->input('channel_secret', ''),
                    'bot_basic_id' => (string) $request->input('bot_basic_id', ''),
                ],
                $request->file('qr_code')
            );

            return $this->redirectWithMessage($path, __('LINE チャネル設定を保存しました。'));
        }

        $this->facebookConfig->saveConfig($request->boolean('enabled'), [
            'page_access_token' => (string) $request->input('page_access_token', ''),
            'app_secret' => (string) $request->input('app_secret', ''),
            'verify_token' => (string) $request->input('verify_token', ''),
            'app_id' => (string) $request->input('app_id', ''),
            'page_name' => (string) $request->input('page_name', ''),
        ]);

        $result = $this->facebookConfig->testConnection();
        $this->facebookConfig->recordTestResult($result['ok'], $result['message']);

        return $this->redirectWithMessage(
            $path,
            __('Facebook Messenger 設定を保存しました。').' '.$result['message'],
            $result['ok'] ? 'notice' : 'error'
        );
    }

    public function disableLine()
    {
        $this->lineConfig->disable();

        return $this->redirectWithMessage(
            $this->settingsPath(MessagingConnection::PROVIDER_LINE),
            __('LINE連携を無効化しました。')
        );
    }

    public function deleteLineQr()
    {
        $this->lineConfig->deleteQrCode();

        return $this->redirectWithMessage(
            $this->settingsPath(MessagingConnection::PROVIDER_LINE),
            __('QRコード画像を削除しました。')
        );
    }

    public function qrCode()
    {
        return $this->lineConfig->qrCodeResponse();
    }

    public function testChannel(string $provider): JsonResponse
    {
        $provider = $this->normalizeProvider($provider);

        if ($provider === MessagingConnection::PROVIDER_LINE) {
            $result = $this->lineConfig->testConnection();
            $this->lineConfig->recordTestResult($result['ok'], $result['message']);

            return response()->json($result, $result['ok'] ? 200 : 422);
        }

        $result = $this->facebookConfig->testConnection();
        $this->facebookConfig->recordTestResult($result['ok'], $result['message']);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function issueCode(Request $request, string $provider)
    {
        $provider = $this->normalizeProvider($provider);
        $path = $this->returnPath($request, $provider);

        if (! $this->providerConfigured($provider)) {
            return $this->redirectWithMessage(
                $path,
                __('チャネルが未設定です。管理者にチャネル設定を依頼してください。'),
                'error'
            );
        }

        $code = $this->links->issueCode($request->user(), $provider);
        $minutes = 15;

        return $this->redirectWithMessage(
            $path,
            __('連携コードを発行しました: :code （:minutes分有効）。公式アカウント／ページにこのコードを送ってください。', [
                'code' => $code,
                'minutes' => $minutes,
            ])
        );
    }

    public function disconnect(Request $request, string $provider)
    {
        $provider = $this->normalizeProvider($provider);
        $this->links->disconnect($request->user(), $provider);

        return $this->redirectWithMessage($this->returnPath($request, $provider), __('連携を解除しました。'));
    }

    public function test(Request $request, string $provider)
    {
        $provider = $this->normalizeProvider($provider);
        $path = $this->returnPath($request, $provider);
        $result = $this->reminders->sendTest($request->user(), $provider);

        if (! empty($result['ok'])) {
            return $this->redirectWithMessage($path, __('テスト通知を送信しました。'));
        }

        return $this->redirectWithMessage(
            $path,
            $result['message'] ?? __('テスト通知に失敗しました。'),
            'error'
        );
    }

    private function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (! in_array($provider, [MessagingConnection::PROVIDER_LINE, MessagingConnection::PROVIDER_MESSENGER], true)) {
            abort(404);
        }

        return $provider;
    }

    private function providerConfigured(string $provider): bool
    {
        return match ($provider) {
            MessagingConnection::PROVIDER_LINE => $this->line->isConfigured(),
            MessagingConnection::PROVIDER_MESSENGER => $this->messenger->isConfigured(),
            default => false,
        };
    }

    private function settingsPath(string $provider): string
    {
        $hash = $provider === MessagingConnection::PROVIDER_LINE ? 'line-messaging' : 'facebook-messenger';

        return '/settings?section=integration#'.$hash;
    }

    private function returnPath(Request $request, string $provider): string
    {
        if (str_starts_with($request->path(), 'mypage/')) {
            $hash = $provider === MessagingConnection::PROVIDER_LINE ? 'line-messaging' : 'facebook-messenger';

            return '/mypage#'.$hash;
        }

        return $this->settingsPath($provider);
    }
}
