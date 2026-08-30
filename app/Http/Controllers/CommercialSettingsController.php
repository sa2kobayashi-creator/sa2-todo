<?php

namespace App\Http\Controllers;

use App\Services\AppInstallConfigService;
use App\Services\LegalConfigService;
use App\Services\StripeBillingService;
use App\Services\StripeConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommercialSettingsController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(
        private readonly LegalConfigService $legal,
        private readonly StripeConfigService $stripe,
        private readonly StripeBillingService $stripeBilling,
        private readonly AppInstallConfigService $appInstall,
    ) {}

    public function updateLegal(Request $request)
    {
        $data = $request->validate([
            'operator_name' => ['nullable', 'string', 'max:120'],
            'operator_trade_name' => ['nullable', 'string', 'max:120'],
            'operator_manager' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'phone_hours' => ['nullable', 'string', 'max:80'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'privacy_contact_email' => ['nullable', 'email', 'max:190'],
            'invoice_registration_number' => ['nullable', 'string', 'max:40'],
        ]);

        $this->legal->save($data);

        return $this->redirectWithMessage(
            '/settings?section=sales#legal-operator-settings',
            __('事業者情報を保存しました。特商法表記に反映されます。')
        );
    }

    public function updateStripe(Request $request)
    {
        $data = $request->validate([
            'stripe_key' => ['nullable', 'string', 'max:200'],
            'stripe_secret' => ['nullable', 'string', 'max:200'],
            'webhook_secret' => ['nullable', 'string', 'max:200'],
            'price_standard_monthly' => ['nullable', 'string', 'max:80'],
            'price_standard_yearly' => ['nullable', 'string', 'max:80'],
            'price_tenant_monthly' => ['nullable', 'string', 'max:80'],
            'price_tenant_yearly' => ['nullable', 'string', 'max:80'],
            'price_mailbox_monthly' => ['nullable', 'string', 'max:80'],
            'price_storage_overage' => ['nullable', 'string', 'max:80'],
        ]);

        $enabled = $request->boolean('enabled');
        if ($enabled) {
            $block = $this->stripeBilling->enableBlockReason($data);
            if ($block !== null) {
                return $this->redirectWithMessage(
                    '/settings?section=sales#stripe-billing-settings',
                    $block,
                    'error'
                );
            }
        }

        $this->stripe->save($enabled, $data);

        return $this->redirectWithMessage(
            '/settings?section=sales#stripe-billing-settings',
            $enabled
                ? __('Stripe 設定を保存し、オンライン申し込みを開始しました。')
                : __('Stripe 設定を保存しました。申し込みボタンはまだ出ていません。')
        );
    }

    public function updateApplications(Request $request)
    {
        $open = $request->boolean('applications_open');
        \App\Support\Registration::setApplicationsOpen($open);

        return $this->redirectWithMessage(
            '/settings?section=sales#registration-applications-settings',
            $open
                ? __('利用申請の受付を開始しました。TOPページに申請ボタンが表示されます。')
                : __('利用申請の受付を停止しました。TOPページの申請ボタンは「準備中」になります。')
        );
    }

    public function updateAppInstall(Request $request)
    {
        $returnTo = '/settings?section=sales#app-install-settings';

        if ($request->boolean('remove_apk')) {
            $this->appInstall->removeLocalApk();

            return $this->redirectWithMessage($returnTo, __('アップロード済みの APK を削除しました。'));
        }

        $data = $request->validate([
            'apk_url' => ['nullable', 'string', 'max:500'],
            'apk_file' => ['nullable', 'file', 'max:153600'],
        ]);

        try {
            if ($request->hasFile('apk_file')) {
                $this->appInstall->storeUploadedApk($request->file('apk_file'));
            }
            $this->appInstall->saveUrl($data['apk_url'] ?? '');
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage(
            $returnTo,
            $request->hasFile('apk_file')
                ? __('APKを保存しました。ダッシュボードのインストールからダウンロードできます。')
                : __('アプリインストール設定を保存しました。')
        );
    }

    public function testStripe(): JsonResponse
    {
        $result = $this->stripe->testConnection();
        $this->stripe->recordTestResult($result['ok'], $result['message']);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
