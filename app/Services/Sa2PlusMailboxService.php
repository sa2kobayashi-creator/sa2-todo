<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\MailDomainRequest;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Sa2PlusMailboxService
{
    public function __construct(
        private BillingEntitlementService $billing,
    ) {}

    public function domain(): string
    {
        return strtolower((string) config('mail_domain.domain', 'sa2-plus.com'));
    }

    /** @deprecated 互換用。有料化後は通常 0 */
    public function freeQuotaPerUser(): int
    {
        return max(0, (int) config('mail_domain.free_mailboxes_per_user', 0));
    }

    public function mailboxesPerUser(): int
    {
        return max(1, (int) config('mail_domain.mailboxes_per_user', 1));
    }

    public function addonPriceYenMonthly(): int
    {
        return max(0, (int) config('mail_domain.addon_price_yen_monthly', 300));
    }

    public function addonPriceYenYearly(): int
    {
        return max(0, (int) config('mail_domain.addon_price_yen_yearly', 3000));
    }

    public function apiComingSoon(): bool
    {
        return (string) config('mail_domain.lolipop.api_mailbox_create_status') === 'coming_soon';
    }

    /** @return list<string> */
    public function reservedLocalParts(): array
    {
        return array_map('strtolower', (array) config('mail_domain.reserved_local_parts', []));
    }

    /**
     * スタンダードはメールボックスがプランに含まれる。
     * ライトは mailbox_addon_active の個別契約。スタッフは常に可。
     * ライトの有料契約（容量など）だけではメールは付かない。
     */
    public function mailboxIncludedInPlan(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->roleEnum() === UserRole::Standard;
    }

    /** プラン同梱・有料オプション・スタッフなら申請資格あり */
    public function userHasMailboxEntitlement(User $user): bool
    {
        return $this->mailboxIncludedInPlan($user) || $this->billing->hasMailboxAddon($user);
    }

    public function userSlotUsed(User $user): int
    {
        return (int) MailDomainRequest::query()
            ->where('user_id', $user->id)
            ->where('domain', $this->domain())
            ->whereIn('status', [
                MailDomainRequest::STATUS_PENDING,
                MailDomainRequest::STATUS_APPROVED,
                MailDomainRequest::STATUS_PROVISIONED,
            ])
            ->count();
    }

    /** @deprecated use userSlotUsed */
    public function userFreeSlotUsed(User $user): int
    {
        return $this->userSlotUsed($user);
    }

    public function userCanRequest(User $user): bool
    {
        $used = $this->userSlotUsed($user);

        if ($this->userHasMailboxEntitlement($user)) {
            return $used < $this->mailboxesPerUser();
        }

        // レガシー: free_mailboxes_per_user > 0 のときだけアドオン無しで申請可
        $free = $this->freeQuotaPerUser();
        if ($free <= 0) {
            return false;
        }

        return $used < $free;
    }

    /** @return list<MailDomainRequest> */
    public function listForUser(User $user): array
    {
        return MailDomainRequest::query()
            ->where('user_id', $user->id)
            ->where('domain', $this->domain())
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    /** @return list<MailDomainRequest> */
    public function listPendingForAdmin(): array
    {
        return MailDomainRequest::query()
            ->with('user')
            ->where('domain', $this->domain())
            ->where(function ($q) {
                $q->whereIn('status', [
                    MailDomainRequest::STATUS_PENDING,
                    MailDomainRequest::STATUS_APPROVED,
                ])->orWhere(function ($q2) {
                    $q2->where('status', MailDomainRequest::STATUS_PROVISIONED)
                        ->whereNotNull('cancel_requested_at');
                });
            })
            ->orderByRaw('CASE WHEN cancel_requested_at IS NOT NULL THEN 0 ELSE 1 END')
            ->orderBy('status')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** @return list<MailDomainRequest> */
    public function listRecentForAdmin(int $limit = 50): array
    {
        return MailDomainRequest::query()
            ->with('user')
            ->where('domain', $this->domain())
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function request(User $user, array $input): MailDomainRequest
    {
        if (! $this->userHasMailboxEntitlement($user) && $this->freeQuotaPerUser() <= 0) {
            throw ValidationException::withMessages([
                'local_part' => __('メールボックスは有料オプションです。お支払い確認後、管理者がオプションを有効にすると申請できます。'),
            ]);
        }

        if (! $this->userCanRequest($user)) {
            throw ValidationException::withMessages([
                'local_part' => __('メールボックスの枠（:count件）はすでに申請済みか取得済みです。', [
                    'count' => $this->mailboxesPerUser(),
                ]),
            ]);
        }

        $validator = Validator::make($input, [
            'local_part' => ['required', 'string', 'min:2', 'max:64', 'regex:/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/'],
            'user_note' => ['nullable', 'string', 'max:1000'],
        ], [
            'local_part.regex' => __('ローカルパートは英小文字・数字・._- のみ（先頭末尾は英数字）です。'),
        ]);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $data = $validator->validated();
        $local = strtolower(trim($data['local_part']));

        if (in_array($local, $this->reservedLocalParts(), true)) {
            throw ValidationException::withMessages([
                'local_part' => __('このローカルパートは予約されているため使えません。'),
            ]);
        }

        $exists = MailDomainRequest::query()
            ->where('domain', $this->domain())
            ->where('local_part', $local)
            ->whereNotIn('status', [MailDomainRequest::STATUS_REJECTED, MailDomainRequest::STATUS_SUSPENDED])
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'local_part' => __('このアドレスはすでに申請または登録されています。'),
            ]);
        }

        return MailDomainRequest::create([
            'user_id' => $user->id,
            'local_part' => $local,
            'domain' => $this->domain(),
            'status' => MailDomainRequest::STATUS_PENDING,
            'user_note' => $data['user_note'] ?? null,
            'provisioning_mode' => 'manual',
        ]);
    }

    /**
     * ユーザー自身によるキャンセル／解約依頼。
     * pending・approved → 却下。provisioned → 解約希望フラグ（管理者が停止する）。
     */
    public function cancelByUser(User $user, int $id): MailDomainRequest
    {
        $req = $this->find($id);
        if ((int) $req->user_id !== (int) $user->id) {
            throw new \InvalidArgumentException(__('この申請を操作する権限がありません。'));
        }

        if (in_array($req->status, [MailDomainRequest::STATUS_PENDING, MailDomainRequest::STATUS_APPROVED], true)) {
            $req->status = MailDomainRequest::STATUS_REJECTED;
            $req->admin_note = __('ユーザーが申請をキャンセルしました。');
            $req->reviewed_at = now();
            $req->cancel_requested_at = null;
            $req->save();

            return $req->refresh();
        }

        if ($req->status === MailDomainRequest::STATUS_PROVISIONED) {
            if ($req->cancel_requested_at) {
                throw new \InvalidArgumentException(__('すでに解約を依頼済みです。管理者が停止するまでお待ちください。'));
            }
            $req->cancel_requested_at = now();
            $req->user_note = trim((string) $req->user_note."\n".__('解約を依頼しました。').' '.now()->toDateTimeString());
            $req->save();

            return $req->refresh();
        }

        throw new \InvalidArgumentException(__('この状態ではキャンセルできません。'));
    }

    public function approve(User $admin, int $id, ?string $adminNote = null): MailDomainRequest
    {
        $req = $this->find($id);
        if ($req->status !== MailDomainRequest::STATUS_PENDING) {
            throw new \InvalidArgumentException(__('承認できるのは申請中のみです。'));
        }

        $req->status = MailDomainRequest::STATUS_APPROVED;
        $req->admin_note = $adminNote;
        $req->reviewed_by = $admin->id;
        $req->reviewed_at = now();
        $req->save();

        // 承認＝有料オプション契約開始（請求書確認後の手動運用）
        if ($req->user && ! $req->user->isAdmin()) {
            $this->billing->apply($req->user, ['mailbox_addon_active' => true]);
        }

        return $req->refresh();
    }

    public function reject(User $admin, int $id, ?string $adminNote = null): MailDomainRequest
    {
        $req = $this->find($id);
        if (! in_array($req->status, [MailDomainRequest::STATUS_PENDING, MailDomainRequest::STATUS_APPROVED], true)) {
            throw new \InvalidArgumentException(__('却下できる状態ではありません。'));
        }

        $req->status = MailDomainRequest::STATUS_REJECTED;
        $req->admin_note = $adminNote;
        $req->reviewed_by = $admin->id;
        $req->reviewed_at = now();
        $req->cancel_requested_at = null;
        $req->save();

        return $req->refresh();
    }

    /**
     * 管理者がロリポップ管理画面で手動作成したあと「作成済み」にする。
     * API 公開後はここから自動作成へ差し替える。
     */
    public function markProvisioned(User $admin, int $id, ?string $adminNote = null): MailDomainRequest
    {
        $req = $this->find($id);
        if (! in_array($req->status, [MailDomainRequest::STATUS_PENDING, MailDomainRequest::STATUS_APPROVED], true)) {
            throw new \InvalidArgumentException(__('作成済みにできる状態ではありません。'));
        }

        if ($this->apiComingSoon()) {
            $req->provisioning_mode = 'manual';
        }

        $req->status = MailDomainRequest::STATUS_PROVISIONED;
        if ($adminNote !== null && $adminNote !== '') {
            $req->admin_note = $adminNote;
        }
        if (! $req->reviewed_by) {
            $req->reviewed_by = $admin->id;
            $req->reviewed_at = now();
        }
        $req->provisioned_by = $admin->id;
        $req->provisioned_at = now();
        $req->save();

        if ($req->user && ! $req->user->isAdmin()) {
            $this->billing->apply($req->user, ['mailbox_addon_active' => true]);
        }

        return $req->refresh();
    }

    public function suspend(User $admin, int $id, ?string $adminNote = null): MailDomainRequest
    {
        $req = $this->find($id);
        if ($req->status !== MailDomainRequest::STATUS_PROVISIONED) {
            throw new \InvalidArgumentException(__('停止できるのは作成済みのみです。'));
        }

        $req->status = MailDomainRequest::STATUS_SUSPENDED;
        $req->admin_note = $adminNote ?? $req->admin_note;
        $req->reviewed_by = $admin->id;
        $req->reviewed_at = now();
        $req->cancel_requested_at = null;
        $req->save();

        // 停止＝有料オプション終了（ホスティング側の削除は手動）
        if ($req->user && ! $req->user->isAdmin()) {
            $stillActive = MailDomainRequest::query()
                ->where('user_id', $req->user_id)
                ->where('domain', $this->domain())
                ->whereIn('status', [
                    MailDomainRequest::STATUS_PENDING,
                    MailDomainRequest::STATUS_APPROVED,
                    MailDomainRequest::STATUS_PROVISIONED,
                ])
                ->where('id', '!=', $req->id)
                ->exists();
            if (! $stillActive) {
                $this->billing->apply($req->user, ['mailbox_addon_active' => false]);
            }
        }

        return $req->refresh();
    }

    public function find(int $id): MailDomainRequest
    {
        $req = MailDomainRequest::query()->with('user')->find($id);
        if (! $req) {
            throw new \InvalidArgumentException(__('申請が見つかりません。'));
        }

        return $req;
    }
}
