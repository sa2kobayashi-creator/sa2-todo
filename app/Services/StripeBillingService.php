<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Checkout;
use Stripe\Exception\InvalidRequestException as StripeInvalidRequestException;
use Stripe\StripeClient;

/**
 * Stripe と既存のエンタイトルメント層をつなぐ層。
 *
 * 機能ゲートの判定は BillingEntitlementService のまま。ここは
 * 「Stripe の状態 → users の4カラム」の写像だけを持つ。
 * 設計の正は docs/specs/commercial-stripe-billing.md
 */
class StripeBillingService
{
    public function __construct(private readonly BillingEntitlementService $entitlements) {}

    /**
     * /mypage/plan とマイページ埋め込みで共有する画面データ。
     *
     * @return array<string, mixed>
     */
    public function planPageData(User $user): array
    {
        return [
            'billingEnabled' => $this->enabled(),
            'plans' => $this->selfServePlans(),
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
        ];
    }

    /**
     * 退会・アカウント削除前に Stripe 上のサブスクを即時解約する。
     *
     * 同一メールの Customer が複数あっても、すべて走査して開いている契約を止める。
     * 鍵があるのに解約確認できない／有料扱いなのに顧客が見つからないときは例外。
     *
     * @throws \RuntimeException 解約できず課金が残る恐れがあるとき
     */
    public function cancelAllSubscriptionsForDeletion(User $user): void
    {
        app(StripeConfigService::class)->applyRuntime();

        $mustStopBilling = $this->userLikelyHasBillableSubscription($user);
        $localSubIds = $this->localOpenStripeSubscriptionIds($user);
        $secret = trim((string) config('cashier.secret', ''));

        if ($secret === '') {
            if ($mustStopBilling || trim((string) $user->stripe_id) !== '' || $localSubIds !== []) {
                Log::warning('stripe cancel on delete skipped: secret missing', [
                    'user_id' => $user->id,
                    'must_stop' => $mustStopBilling,
                ]);
                throw new \RuntimeException(
                    __('有料契約の解約に失敗しました。プラン・お支払いから解約してから、もう一度退会してください。')
                );
            }

            return;
        }

        $stripe = new StripeClient($secret);
        $canceledIds = [];
        $customerIds = [];

        try {
            $known = trim((string) $user->stripe_id);
            if (str_starts_with($known, 'cus_')) {
                $customerIds[$known] = $known;
            }

            foreach ($this->findAllStripeCustomerIdsByEmail($stripe, (string) $user->email) as $id) {
                $customerIds[$id] = $id;
            }

            foreach ($localSubIds as $subscriptionId) {
                $this->cancelStripeSubscriptionId($stripe, $subscriptionId, $user, $canceledIds);
                $fromSub = $this->customerIdFromSubscription($stripe, $subscriptionId);
                if (str_starts_with($fromSub, 'cus_')) {
                    $customerIds[$fromSub] = $fromSub;
                }
            }

            // 鍵があるのに Customer を一切特定できない＋有料扱い → 退会中止
            if ($customerIds === [] && $localSubIds === [] && $mustStopBilling) {
                Log::error('stripe cancel on delete: no customer for entitled user', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);
                throw new \RuntimeException(
                    __('有料契約の解約に失敗しました。プラン・お支払いから解約してから、もう一度退会してください。')
                );
            }

            // Customer が無い・ローカル sub も無い・アプリ上も未契約 → 何もしない
            if ($customerIds === [] && $localSubIds === [] && ! $mustStopBilling) {
                Log::info('stripe cancel on delete: nothing to cancel', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);

                return;
            }

            if ($customerIds !== [] && trim((string) $user->stripe_id) === '') {
                $user->forceFill(['stripe_id' => array_values($customerIds)[0]])->save();
            }

            foreach ($customerIds as $customerId) {
                $page = $stripe->subscriptions->all([
                    'customer' => $customerId,
                    'status' => 'all',
                    'limit' => 100,
                ]);
                foreach ($page->data as $subscription) {
                    $status = (string) ($subscription->status ?? '');
                    if (in_array($status, ['canceled', 'incomplete_expired'], true)) {
                        continue;
                    }
                    $this->cancelStripeSubscriptionId(
                        $stripe,
                        (string) $subscription->id,
                        $user,
                        $canceledIds
                    );
                }
            }

            foreach ($customerIds as $customerId) {
                $remaining = $stripe->subscriptions->all([
                    'customer' => $customerId,
                    'status' => 'all',
                    'limit' => 100,
                ]);
                foreach ($remaining->data as $subscription) {
                    $status = (string) ($subscription->status ?? '');
                    if (! in_array($status, ['canceled', 'incomplete_expired'], true)) {
                        Log::error('stripe cancel on delete: open subscription remains', [
                            'user_id' => $user->id,
                            'customer' => $customerId,
                            'subscription_id' => $subscription->id ?? null,
                            'status' => $status,
                        ]);
                        throw new \RuntimeException(
                            __('有料契約の解約に失敗しました。プラン・お支払いから解約してから、もう一度退会してください。')
                        );
                    }
                }
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            Log::error('stripe cancel on delete failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(
                __('有料契約の解約に失敗しました。プラン・お支払いから解約してから、もう一度退会してください。'),
                0,
                $e
            );
        }

        Log::info('stripe subscriptions canceled for account deletion', [
            'user_id' => $user->id,
            'customers' => array_values($customerIds),
            'canceled' => array_values($canceledIds),
        ]);
    }

    private function userLikelyHasBillableSubscription(User $user): bool
    {
        if ($this->entitlements->hasActiveSubscription($user)) {
            return true;
        }

        $status = $this->entitlements->status($user);

        return in_array($status, [
            SubscriptionStatus::Trial,
            SubscriptionStatus::Active,
            SubscriptionStatus::PastDue,
        ], true);
    }

    /** @return list<string> */
    private function localOpenStripeSubscriptionIds(User $user): array
    {
        try {
            return $user->subscriptions()
                ->whereNotNull('stripe_id')
                ->whereNotIn('stripe_status', ['canceled', 'incomplete_expired'])
                ->pluck('stripe_id')
                ->map(fn ($id) => trim((string) $id))
                ->filter(fn (string $id) => str_starts_with($id, 'sub_'))
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    private function findAllStripeCustomerIdsByEmail(StripeClient $stripe, string $email): array
    {
        $email = strtolower(trim($email));
        if ($email === '' || ! str_contains($email, '@')) {
            return [];
        }

        try {
            $page = $stripe->customers->all([
                'email' => $email,
                'limit' => 100,
            ]);
        } catch (\Throwable $e) {
            Log::warning('stripe cancel on delete: customer email lookup failed', [
                'email' => $email,
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        $ids = [];
        foreach ($page->data as $customer) {
            $id = trim((string) ($customer->id ?? ''));
            if (str_starts_with($id, 'cus_')) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function customerIdFromSubscription(StripeClient $stripe, string $subscriptionId): string
    {
        try {
            $subscription = $stripe->subscriptions->retrieve($subscriptionId);
            $customer = $subscription->customer ?? null;
            if (is_object($customer) && isset($customer->id)) {
                return trim((string) $customer->id);
            }

            return trim((string) $customer);
        } catch (\Throwable $e) {
            Log::warning('stripe cancel on delete: subscription retrieve for customer failed', [
                'subscription_id' => $subscriptionId,
                'message' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * @param  array<string, string>  $canceledIds
     */
    private function cancelStripeSubscriptionId(
        StripeClient $stripe,
        string $subscriptionId,
        User $user,
        array &$canceledIds,
    ): void {
        $subscriptionId = trim($subscriptionId);
        if ($subscriptionId === '' || isset($canceledIds[$subscriptionId])) {
            return;
        }

        try {
            $current = $stripe->subscriptions->retrieve($subscriptionId);
            $status = (string) ($current->status ?? '');
            if (in_array($status, ['canceled', 'incomplete_expired'], true)) {
                $canceledIds[$subscriptionId] = $subscriptionId;

                return;
            }

            $stripe->subscriptions->cancel($subscriptionId);
            $canceledIds[$subscriptionId] = $subscriptionId;
        } catch (\Throwable $e) {
            report($e);
            Log::error('stripe cancel on delete: cancel failed', [
                'user_id' => $user->id,
                'subscription_id' => $subscriptionId,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(
                __('有料契約の解約に失敗しました。プラン・お支払いから解約してから、もう一度退会してください。'),
                0,
                $e
            );
        }
    }

    public function enabled(): bool
    {
        if (! (bool) config('billing.enabled', false)) {
            return false;
        }

        if (trim((string) config('cashier.secret', '')) === '') {
            return false;
        }

        if (trim((string) config('cashier.webhook.secret', '')) === '') {
            return false;
        }

        if ($this->selfServePlans() === []) {
            return false;
        }

        // .env だけで BILLING_ENABLED=true にしても、特商法の必須項目が空なら申込を出さない
        return app(LegalConfigService::class)->requiredComplete();
    }

    /**
     * 画面から「オンライン申し込みを開始」できるか。できない理由を返す。
     *
     * @param  array<string, mixed>  $incoming  保存直前の入力（マスク済みは無視）
     */
    public function enableBlockReason(array $incoming = []): ?string
    {
        if (! app(LegalConfigService::class)->requiredComplete()) {
            return __('オンライン申し込みを始める前に、上の事業者情報（氏名・住所・電話・メール）を保存してください。');
        }

        $secret = $this->effectiveSecret('stripe_secret', (string) ($incoming['stripe_secret'] ?? ''));
        if ($secret === '') {
            return __('シークレットキー（sk_）を保存してください。');
        }

        $webhook = $this->effectiveSecret('webhook_secret', (string) ($incoming['webhook_secret'] ?? ''));
        if ($webhook === '') {
            return __('Webhook 署名シークレット（whsec_）を保存してください。決済完了を受け取れません。');
        }

        $hasPrice = false;
        foreach (['price_standard_monthly', 'price_standard_yearly'] as $key) {
            $fromInput = trim((string) ($incoming[$key] ?? ''));
            $fromConfig = match ($key) {
                'price_standard_monthly' => (string) config('billing.plans.standard_monthly.price_id', ''),
                'price_standard_yearly' => (string) config('billing.plans.standard_yearly.price_id', ''),
                default => '',
            };
            $candidate = $fromInput !== '' ? $fromInput : trim($fromConfig);
            if ($candidate === '') {
                continue;
            }
            if (! $this->looksLikeStripePriceId($candidate)) {
                return __('Price ID は price_ で始まる値にしてください。金額（980 など）は使えません。Stripe ダッシュボードの「価格」ID をコピーしてください。');
            }
            $hasPrice = true;
        }

        if (! $hasPrice) {
            return __('スタンダードの Price ID（月額または年額）を少なくとも1つ保存してください。');
        }

        return null;
    }

    public function looksLikeStripePriceId(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || ctype_digit($value)) {
            return false;
        }

        // Stripe は price_1ABC...。テスト用の price_standard_1 なども許可する。
        return (bool) preg_match('/^price_[A-Za-z0-9_]+$/', $value);
    }

    private function effectiveSecret(string $dbKey, string $incoming): string
    {
        $value = trim($incoming);
        if ($value !== '' && $value !== '••••••••' && ! str_starts_with($value, '••••')) {
            return $value;
        }

        $configKey = $dbKey === 'webhook_secret' ? 'cashier.webhook.secret' : 'cashier.secret';

        return trim((string) config($configKey, ''));
    }

    /**
     * 画面から申し込めるプラン一覧。price 未設定のものは出さない。
     *
     * @return array<string, array<string, mixed>>
     */
    public function selfServePlans(): array
    {
        return array_filter(
            (array) config('billing.plans', []),
            fn (array $plan) => ($plan['self_serve'] ?? false)
                && $this->looksLikeStripePriceId((string) ($plan['price_id'] ?? ''))
        );
    }

    /**
     * プランキーから price を引く。利用者に price ID を選ばせないための allowlist。
     *
     * @return array<string, mixed>|null
     */
    public function planByKey(string $key): ?array
    {
        $plan = (array) config('billing.plans', []);
        if (! isset($plan[$key]) || ! $this->looksLikeStripePriceId((string) ($plan[$key]['price_id'] ?? ''))) {
            return null;
        }

        return ((array) $plan[$key]) + ['key' => $key];
    }

    /**
     * Checkout Session を作る。古い stripe_id（テスト／本番の食い違い等）は一度消して再試行する。
     *
     * @param  array<string, mixed>  $plan
     *
     * @throws \Throwable
     */
    public function createCheckoutSession(User $user, array $plan, string $returnTo): Checkout
    {
        try {
            return $this->buildCheckoutSession($user, $plan, $returnTo);
        } catch (StripeInvalidRequestException $e) {
            if (! $this->isMissingStripeCustomerError($e) || ! $user->hasStripeId()) {
                throw $e;
            }

            Log::warning('stripe checkout: clearing stale customer id', [
                'user_id' => $user->id,
                'stripe_id' => $user->stripe_id,
                'message' => $e->getMessage(),
            ]);
            $this->forgetStripeCustomer($user);

            return $this->buildCheckoutSession($user->fresh() ?? $user, $plan, $returnTo);
        }
    }

    /**
     * 利用者向けの短い案内。秘密情報は出さない。
     */
    public function checkoutFailureMessage(\Throwable $e): string
    {
        $msg = $e->getMessage();

        if ($this->isMissingStripeCustomerError($e)) {
            return __('決済用の顧客情報が見つかりませんでした。もう一度「申し込む」を押してください。');
        }

        if (stripos($msg, 'No such price') !== false || stripos($msg, 'No such plan') !== false) {
            return __('料金プランの設定（Price ID）が Stripe の鍵のモード（テスト／本番）と一致していない可能性があります。運営の設定を確認してください。');
        }

        if (
            stripos($msg, 'literal numerical price') !== false
            || stripos($msg, 'should be the ID of a price object') !== false
        ) {
            return __('料金プランの Price ID が正しくありません。金額（例: 980）ではなく、Stripe の price_ で始まる ID を設定してください。');
        }

        if (
            stripos($msg, 'product tax code is missing') !== false
            || stripos($msg, 'Managed Payments') !== false
        ) {
            return __('Stripe の Managed Payments（税コード必須）が有効なため決済を開けませんでした。商品に税コードを付けるか、Managed Payments をオフにしてください。');
        }

        if (stripos($msg, 'Invalid API Key') !== false || stripos($msg, 'Invalid API key') !== false) {
            return __('Stripe の API キーが無効です。設定を確認してください。');
        }

        return __('決済ページを開けませんでした。時間をおいて再度お試しください。');
    }

    public function forgetStripeCustomer(User $user): void
    {
        $user->forceFill([
            'stripe_id' => null,
            'pm_type' => null,
            'pm_last_four' => null,
        ])->save();
    }

    public function isMissingStripeCustomerError(\Throwable $e): bool
    {
        $msg = $e->getMessage();

        return stripos($msg, 'No such customer') !== false;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function buildCheckoutSession(User $user, array $plan, string $returnTo): Checkout
    {
        return $user
            ->newSubscription('default', (string) $plan['price_id'])
            ->trialDays(max(0, (int) ($plan['trial_days'] ?? 0)))
            ->allowPromotionCodes()
            ->checkout([
                'success_url' => url($returnTo).'?notice='.urlencode(__('お申し込みを受け付けました。反映まで少しお待ちください。')),
                'cancel_url' => url($returnTo),
                'locale' => 'ja',
                'client_reference_id' => (string) $user->id,
                // 新規 Stripe アカウントは Managed Payments が既定 ON で、商品税コードが無いと Checkout が落ちる。
                // 自社が販売者の通常サブスクでは不要なので明示的にオフにする。
                'managed_payments' => ['enabled' => false],
            ]);
    }

    /** price ID から逆引きする。Webhook が受け取るのは price なので必要になる */
    public function planByPriceId(string $priceId): ?array
    {
        if (trim($priceId) === '') {
            return null;
        }

        foreach ((array) config('billing.plans', []) as $key => $plan) {
            if ((string) ($plan['price_id'] ?? '') === $priceId) {
                return $plan + ['key' => $key];
            }
        }

        return null;
    }

    /** アドオンの price ID → 付与するカラム名 */
    public function addonFlagByPriceId(string $priceId): ?string
    {
        if (trim($priceId) === '') {
            return null;
        }

        foreach ((array) config('billing.addons', []) as $addon) {
            if ((string) ($addon['price_id'] ?? '') === $priceId) {
                return (string) ($addon['grants'] ?? '') ?: null;
            }
        }

        return null;
    }

    /** Stripe のサブスク状態を、このアプリの契約状態に写す */
    public function mapStatus(string $stripeStatus): ?SubscriptionStatus
    {
        return match ($stripeStatus) {
            'trialing' => SubscriptionStatus::Trial,
            'active' => SubscriptionStatus::Active,
            'past_due', 'unpaid' => SubscriptionStatus::PastDue,
            'canceled', 'incomplete_expired' => SubscriptionStatus::Canceled,
            // incomplete は決済が終わっていない。まだ何も開けないので触らない
            default => null,
        };
    }

    /**
     * Stripe のサブスク1件をユーザーに反映する。
     *
     * @param  array<string, mixed>  $subscription  Stripe の subscription オブジェクト（配列）
     */
    public function applySubscription(User $user, array $subscription): User
    {
        $stripeStatus = (string) ($subscription['status'] ?? '');
        // 期末解約予約中でも、すでに終了時刻を過ぎていれば canceled 扱いにする
        if (
            $stripeStatus !== 'canceled'
            && $stripeStatus !== 'incomplete_expired'
            && ! empty($subscription['cancel_at_period_end'])
        ) {
            $endTs = (int) ($subscription['ended_at']
                ?? $subscription['cancel_at']
                ?? $subscription['trial_end']
                ?? $subscription['current_period_end']
                ?? 0);
            if ($endTs > 0 && $endTs <= time()) {
                $stripeStatus = 'canceled';
            }
        }

        $status = $this->mapStatus($stripeStatus);
        if ($status === null) {
            return $user;
        }

        $attrs = ['subscription_status' => $status];

        // Stripe は UNIX 秒。アプリのタイムゾーンで持たないと保存時に9時間ずれる
        $trialEnd = $subscription['trial_end'] ?? null;
        $attrs['trial_ends_at'] = $status === SubscriptionStatus::Trial && $trialEnd
            ? Carbon::createFromTimestamp((int) $trialEnd, config('app.timezone'))
            : null;

        $priceIds = $this->priceIdsOf($subscription);
        $grantsPaidAccess = $status->grantsPaidAccess();

        foreach ((array) config('billing.addons', []) as $addon) {
            $flag = (string) ($addon['grants'] ?? '');
            if ($flag === '') {
                continue;
            }
            $subscribed = in_array((string) ($addon['price_id'] ?? ''), $priceIds, true);
            $attrs[$flag] = $grantsPaidAccess && $subscribed;
        }

        $user = $this->entitlements->apply($user, $attrs);

        $this->syncRole($user, $priceIds, $grantsPaidAccess);

        return $user->refresh();
    }

    /**
     * プランに紐づくロールを反映する。
     *
     * 運営が手で管理する SuperAdmin / Admin（テナント代表）は決済イベントで動かさない。
     * 動かしてよいのは Light ↔ Standard だけ。
     *
     * @param  list<string>  $priceIds
     */
    private function syncRole(User $user, array $priceIds, bool $grantsPaidAccess): void
    {
        $role = $user->roleEnum();
        if ($role !== UserRole::Light && $role !== UserRole::Standard) {
            return;
        }

        $target = null;
        foreach ($priceIds as $priceId) {
            $plan = $this->planByPriceId($priceId);
            $planRole = $plan['role'] ?? null;
            if ($planRole !== null) {
                $target = UserRole::tryFrom((string) $planRole);
                break;
            }
        }

        $next = $grantsPaidAccess && $target !== null ? $target : UserRole::Light;
        if ($next === $role) {
            return;
        }

        $user->role = $next;
        $user->save();
    }

    /**
     * 支払い遅延から猶予日数を過ぎたか。過ぎたら有料機能を閉じる。
     * 判定の正は BillingEntitlementService。ここは呼び出し互換のための委譲。
     */
    public function pastDueGraceExpired(User $user, ?Carbon $since = null): bool
    {
        return $this->entitlements->pastDueGraceExpired($user, $since);
    }

    /**
     * 請求書イベントは契約状態だけ更新する。
     * 権限・ロール・アドオンの正は customer.subscription.*（明細が請求ごとに変わるため）。
     */
    public function applyInvoiceStatus(User $user, bool $paid): User
    {
        return $this->entitlements->apply($user, [
            'subscription_status' => $paid
                ? SubscriptionStatus::Active
                : SubscriptionStatus::PastDue,
        ]);
    }

    /**
     * 超過バイト数を課金単位（100GB 切り上げ）に変換する。
     */
    public function overageUnits(int $usedBytes, int $quotaBytes): int
    {
        $unit = max(1, (int) config('billing.addons.storage_overage.unit_bytes', 100 * 1024 ** 3));
        $over = $usedBytes - $quotaBytes;

        return $over <= 0 ? 0 : (int) ceil($over / $unit);
    }

    /**
     * Stripe の subscription から price ID を取り出す。
     *
     * @param  array<string, mixed>  $subscription
     * @return list<string>
     */
    private function priceIdsOf(array $subscription): array
    {
        $items = $subscription['items']['data'] ?? [];
        $ids = [];
        foreach ((array) $items as $item) {
            $priceId = $item['price']['id'] ?? $item['plan']['id'] ?? null;
            if (is_string($priceId) && $priceId !== '') {
                $ids[] = $priceId;
            }
        }

        return array_values(array_unique($ids));
    }
}
