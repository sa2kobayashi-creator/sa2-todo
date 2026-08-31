<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Checkout;
use Stripe\Exception\InvalidRequestException as StripeInvalidRequestException;

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
        $status = $this->mapStatus((string) ($subscription['status'] ?? ''));
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
