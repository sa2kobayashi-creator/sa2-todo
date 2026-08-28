<?php

namespace App\Http\Controllers;

use App\Models\BillingEvent;
use App\Models\User;
use App\Services\StripeBillingService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * Stripe Webhook。権限の付与はここだけで行う（成功 URL は偽装できる）。
 * 設計の正は docs/specs/commercial-stripe-billing.md
 */
class StripeWebhookController extends Controller
{
    public function __construct(private readonly StripeBillingService $stripe) {}

    public function __invoke(Request $request): Response
    {
        $secret = trim((string) config('cashier.webhook.secret', ''));
        if ($secret === '') {
            Log::warning('stripe webhook secret is not configured');

            return response('', 400);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature', ''),
                $secret,
                (int) config('cashier.webhook.tolerance', 300)
            );
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            return response('', 400);
        }

        $payload = $event->toArray();
        $eventId = (string) ($payload['id'] ?? '');
        $type = (string) ($payload['type'] ?? '');

        try {
            $record = BillingEvent::create([
                'stripe_event_id' => $eventId,
                'type' => $type,
                'payload' => $payload,
            ]);
        } catch (QueryException $e) {
            // unique 制約 = 再送。処理済みとして 200 を返す
            return response('', 200);
        }

        try {
            $user = $this->handle($type, (array) ($payload['data']['object'] ?? []));
            $record->forceFill([
                'user_id' => $user?->id,
                'status' => BillingEvent::STATUS_PROCESSED,
                'processed_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            report($e);
            $record->forceFill([
                'status' => BillingEvent::STATUS_FAILED,
                'error' => $e->getMessage(),
            ])->save();

            // 500 を返して Stripe に再送させる
            return response('', 500);
        }

        return response('', 200);
    }

    /** @param array<string, mixed> $object */
    private function handle(string $type, array $object): ?User
    {
        return match ($type) {
            'checkout.session.completed' => $this->linkCustomer($object),
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->applySubscription($object),
            'invoice.paid',
            'invoice.payment_failed' => $this->applyInvoice($object),
            default => null,
        };
    }

    /** @param array<string, mixed> $session */
    private function linkCustomer(array $session): ?User
    {
        $customerId = (string) ($session['customer'] ?? '');
        $reference = (string) ($session['client_reference_id'] ?? '');
        if ($customerId === '') {
            return null;
        }

        $user = $this->findUser($customerId, $reference);
        if ($user === null) {
            return null;
        }

        if (trim((string) $user->stripe_id) === '') {
            $user->forceFill(['stripe_id' => $customerId])->save();
        }

        return $user;
    }

    /** @param array<string, mixed> $subscription */
    private function applySubscription(array $subscription): ?User
    {
        $user = $this->findUser((string) ($subscription['customer'] ?? ''));
        if ($user === null) {
            return null;
        }

        // 解約完了は canceled として扱う（Stripe が status を送らないことがある）
        if (($subscription['status'] ?? '') === '') {
            $subscription['status'] = 'canceled';
        }

        return $this->stripe->applySubscription($user, $subscription);
    }

    /** @param array<string, mixed> $invoice */
    private function applyInvoice(array $invoice): ?User
    {
        $user = $this->findUser((string) ($invoice['customer'] ?? ''));
        if ($user === null) {
            return null;
        }

        // 明細から権限を再計算しない。月次請求に Standard しか載らないと mailbox 等が落ちる
        return $this->stripe->applyInvoiceStatus($user, (bool) ($invoice['paid'] ?? false));
    }

    private function findUser(string $customerId, string $reference = ''): ?User
    {
        if ($customerId !== '') {
            $user = User::query()->where('stripe_id', $customerId)->first();
            if ($user) {
                return $user;
            }
        }

        return $reference !== '' && ctype_digit($reference)
            ? User::query()->find((int) $reference)
            : null;
    }
}
