<?php

namespace App\Services;

use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MailAccountService
{
    /** @return list<MailAccount> */
    public function listForUser(User $user): array
    {
        return MailAccount::query()
            ->where('user_id', $user->id)
            ->orderBy('label')
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function findOwned(User $user, int $id): MailAccount
    {
        $account = MailAccount::query()
            ->where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (! $account) {
            throw new \InvalidArgumentException(__('メールアカウントが見つかりません。'));
        }

        return $account;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $user, array $input): MailAccount
    {
        $data = $this->validated($user, $input, requirePassword: true);
        $data['user_id'] = $user->id;
        $data['is_sa2_plus_mailbox'] = $this->isSa2PlusAddress($data['email']);

        return MailAccount::create($data);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, int $id, array $input): MailAccount
    {
        $account = $this->findOwned($user, $id);
        $requirePassword = filled($input['password'] ?? null);
        $data = $this->validated($user, $input, requirePassword: $requirePassword, existing: $account);
        if (! $requirePassword) {
            unset($data['password']);
        }
        $data['is_sa2_plus_mailbox'] = $this->isSa2PlusAddress($data['email']);
        $account->fill($data);
        $account->save();

        return $account->refresh();
    }

    public function delete(User $user, int $id): void
    {
        $this->findOwned($user, $id)->delete();
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function testConnection(User $user, int $id, MailClientService $client): array
    {
        $account = $this->findOwned($user, $id);
        try {
            $client->ping($account);
            $account->last_tested_at = now();
            $account->last_test_status = 'ok';
            $account->last_test_message = __('接続に成功しました。');
            $account->save();

            return ['ok' => true, 'message' => $account->last_test_message];
        } catch (\Throwable $e) {
            $account->last_tested_at = now();
            $account->last_test_status = 'fail';
            $account->last_test_message = mb_substr($e->getMessage(), 0, 480);
            $account->save();

            return ['ok' => false, 'message' => $account->last_test_message];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function preset(string $provider, ?string $email = null): array
    {
        $email = strtolower(trim((string) $email));

        return match ($provider) {
            'gmail' => [
                'provider' => 'gmail',
                'label' => $email !== '' ? $email : 'Gmail',
                'email' => $email,
                'username' => $email,
                'imap_host' => (string) config('mail_domain.gmail.imap_host'),
                'imap_port' => (int) config('mail_domain.gmail.imap_port'),
                'imap_encryption' => (string) config('mail_domain.gmail.imap_encryption'),
                'smtp_host' => (string) config('mail_domain.gmail.smtp_host'),
                'smtp_port' => (int) config('mail_domain.gmail.smtp_port'),
                'smtp_encryption' => (string) config('mail_domain.gmail.smtp_encryption'),
            ],
            'lolipop', 'sa2_plus' => [
                'provider' => 'lolipop',
                'label' => $email !== '' ? $email : ('@'.config('mail_domain.domain')),
                'email' => $email,
                'username' => $email,
                'imap_host' => (string) config('mail_domain.lolipop.imap_host'),
                'imap_port' => (int) config('mail_domain.lolipop.imap_port'),
                'imap_encryption' => (string) config('mail_domain.lolipop.imap_encryption'),
                'smtp_host' => (string) config('mail_domain.lolipop.smtp_host'),
                'smtp_port' => (int) config('mail_domain.lolipop.smtp_port'),
                'smtp_encryption' => (string) config('mail_domain.lolipop.smtp_encryption'),
            ],
            default => [
                'provider' => 'custom',
                'label' => $email !== '' ? $email : __('カスタム'),
                'email' => $email,
                'username' => $email,
                'imap_host' => '',
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'smtp_host' => '',
                'smtp_port' => 465,
                'smtp_encryption' => 'ssl',
            ],
        };
    }

    private function isSa2PlusAddress(string $email): bool
    {
        $domain = strtolower((string) config('mail_domain.domain'));
        $parts = explode('@', strtolower($email), 2);

        return count($parts) === 2 && $parts[1] === $domain;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(User $user, array $input, bool $requirePassword, ?MailAccount $existing = null): array
    {
        $provider = (string) ($input['provider'] ?? 'custom');
        if (in_array($provider, ['gmail', 'lolipop', 'sa2_plus'], true) && empty($input['imap_host'])) {
            $preset = $this->preset($provider === 'sa2_plus' ? 'lolipop' : $provider, $input['email'] ?? null);
            $input = array_merge($preset, array_filter($input, fn ($v) => $v !== null && $v !== ''));
            if ($provider === 'sa2_plus') {
                $input['provider'] = 'lolipop';
            }
        }

        $rules = [
            'label' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'provider' => ['required', 'in:gmail,lolipop,custom'],
            'imap_host' => ['required', 'string', 'max:255'],
            'imap_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'imap_encryption' => ['required', 'in:ssl,tls,none'],
            'smtp_host' => ['required', 'string', 'max:255'],
            'smtp_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'smtp_encryption' => ['required', 'in:ssl,tls,none'],
            'username' => ['required', 'string', 'max:255'],
            'password' => [$requirePassword ? 'required' : 'nullable', 'string', 'max:255'],
        ];

        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $data = $validator->validated();
        $data['email'] = strtolower(trim($data['email']));
        $data['username'] = trim($data['username']);
        if (($data['provider'] ?? '') === 'gmail') {
            // Gmail はユーザー名にメールアドレス全体が必要
            $data['username'] = $data['email'];
        }
        if (array_key_exists('password', $data) && is_string($data['password'])) {
            // Gmail アプリパスワードは「xxxx xxxx xxxx xxxx」表示のため空白を除去
            $data['password'] = preg_replace('/\s+/u', '', $data['password']) ?? $data['password'];
        }

        $dup = MailAccount::query()
            ->where('user_id', $user->id)
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
            ->where('email', $data['email'])
            ->exists();
        if ($dup) {
            throw ValidationException::withMessages([
                'email' => __('このメールアドレスはすでに登録されています。'),
            ]);
        }

        return $data;
    }
}
