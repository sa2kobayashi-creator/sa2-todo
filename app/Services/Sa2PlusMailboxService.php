<?php

namespace App\Services;

use App\Models\MailAccount;
use App\Models\MailDomainRequest;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Sa2PlusMailboxService
{
    public function domain(): string
    {
        return strtolower((string) config('mail_domain.domain', 'sa2-plus.com'));
    }

    public function freeQuotaPerUser(): int
    {
        return max(1, (int) config('mail_domain.free_mailboxes_per_user', 1));
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

    public function userFreeSlotUsed(User $user): int
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

    public function userCanRequest(User $user): bool
    {
        return $this->userFreeSlotUsed($user) < $this->freeQuotaPerUser();
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
            ->whereIn('status', [
                MailDomainRequest::STATUS_PENDING,
                MailDomainRequest::STATUS_APPROVED,
            ])
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
        if (! $this->userCanRequest($user)) {
            throw ValidationException::withMessages([
                'local_part' => __('無料枠（契約あたり:count件）はすでに申請済みか取得済みです。', [
                    'count' => $this->freeQuotaPerUser(),
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
            ->whereNotIn('status', [MailDomainRequest::STATUS_REJECTED])
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
            // 明示: まだ API では作っていない
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
        $req->save();

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
