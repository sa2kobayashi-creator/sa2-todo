<?php

namespace App\Http\Controllers;

use App\Services\BillingEntitlementService;
use App\Services\StripeBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BillingController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(
        private readonly StripeBillingService $stripe,
        private readonly BillingEntitlementService $entitlements,
    ) {}

    public function plan(Request $request)
    {
        $user = $request->user();

        return view('billing.plan', [
            'billingEnabled' => $this->stripe->enabled(),
            'plans' => $this->stripe->selfServePlans(),
            'status' => $this->entitlements->status($user),
            'statusLabel' => __($this->entitlements->status($user)->label()),
            'hasActiveSubscription' => $this->entitlements->hasActiveSubscription($user),
            'trialEndsAt' => optional($user->trial_ends_at)?->format('Y-m-d'),
            'mailboxAddonActive' => (bool) $user->mailbox_addon_active,
            'storageOverageActive' => (bool) $user->storage_overage_active,
            'hasStripeCustomer' => trim((string) $user->stripe_id) !== '',
            'pricesIncludeTax' => (bool) config('commercial.prices_include_tax', true),
            'standardMonthlyYen' => (int) config('commercial.standard_yen_monthly', 980),
            'standardYearlyYen' => (int) config('commercial.standard_yen_yearly', 9800),
            'standardTrialDays' => (int) config('commercial.standard_trial_days', 14),
            ...$this->flashFromQuery($request),
        ]);
    }

    public function checkout(Request $request)
    {
        $returnTo = (string) config('billing.return_path', '/mypage/plan');

        if (! $this->stripe->enabled()) {
            return $this->redirectWithMessage($returnTo, __('現在お申し込みを受け付けていません。'), 'error');
        }

        $user = $request->user();
        if ($this->entitlements->hasActiveSubscription($user)) {
            // 二重契約を作らせない。変更は Billing Portal 側で行う
            return $this->redirectWithMessage($returnTo, __('すでに有効なご契約があります。プランの変更は「契約内容を変更」からお願いします。'), 'error');
        }

        $plan = $this->stripe->planByKey((string) $request->input('plan', ''));
        if ($plan === null || ! ($plan['self_serve'] ?? false)) {
            return $this->redirectWithMessage($returnTo, __('選択されたプランは申し込めません。'), 'error');
        }

        try {
            $checkout = $user
                ->newSubscription('default', (string) $plan['price_id'])
                ->trialDays(max(0, (int) ($plan['trial_days'] ?? 0)))
                ->allowPromotionCodes()
                ->checkout([
                    'success_url' => url($returnTo).'?notice='.urlencode(__('お申し込みを受け付けました。反映まで少しお待ちください。')),
                    'cancel_url' => url($returnTo),
                    'locale' => 'ja',
                    'client_reference_id' => (string) $user->id,
                ]);
        } catch (\Throwable $e) {
            report($e);

            return $this->redirectWithMessage($returnTo, __('決済ページを開けませんでした。時間をおいて再度お試しください。'), 'error');
        }

        return redirect($checkout->url);
    }

    public function portal(Request $request)
    {
        $returnTo = (string) config('billing.return_path', '/mypage/plan');
        $user = $request->user();

        if (! $this->stripe->enabled() || trim((string) $user->stripe_id) === '') {
            return $this->redirectWithMessage($returnTo, __('お手続きできるご契約がありません。'), 'error');
        }

        try {
            return $user->redirectToBillingPortal(url($returnTo));
        } catch (\Throwable $e) {
            report($e);
            Log::warning('stripe portal failed', ['user_id' => $user->id]);

            return $this->redirectWithMessage($returnTo, __('契約内容の画面を開けませんでした。時間をおいて再度お試しください。'), 'error');
        }
    }
}
