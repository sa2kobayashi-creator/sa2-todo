<?php

namespace App\Services;

use App\Models\MailAccount;
use App\Models\MailSenderRule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MailSenderRuleService
{
    public function __construct(
        private MailClientService $client,
    ) {}

    /** @return list<MailSenderRule> */
    public function listForAccount(MailAccount $account): array
    {
        return MailSenderRule::query()
            ->where('mail_account_id', $account->id)
            ->where('is_active', true)
            ->orderBy('match_value')
            ->get()
            ->all();
    }

    public function addRule(MailAccount $account, string $email): MailSenderRule
    {
        $normalized = $this->normalizeEmail($email);
        if ($normalized === '') {
            throw new \InvalidArgumentException(__('送信者のメールアドレスが不正です。'));
        }

        return MailSenderRule::query()->updateOrCreate(
            [
                'mail_account_id' => $account->id,
                'match_value' => $normalized,
            ],
            [
                'action' => 'junk',
                'is_active' => true,
            ]
        );
    }

    public function removeRule(MailAccount $account, string $email): void
    {
        $normalized = $this->normalizeEmail($email);
        if ($normalized === '') {
            return;
        }

        MailSenderRule::query()
            ->where('mail_account_id', $account->id)
            ->where('match_value', $normalized)
            ->delete();
    }

    /**
     * @param  list<array{uid: int, from: string}>  $messages
     */
    public function applyRulesToMessages(MailAccount $account, array $messages): int
    {
        if (! $account->is_sa2_plus_mailbox || $messages === []) {
            return 0;
        }

        $rules = $this->listForAccount($account);
        if ($rules === []) {
            return 0;
        }

        $emails = array_map(fn (MailSenderRule $r) => strtolower($r->match_value), $rules);
        $emails = array_values(array_unique($emails));
        $junkPath = $this->client->appSystemFolderPath('junk');
        if ($junkPath === null) {
            return 0;
        }

        $moved = 0;
        foreach ($messages as $row) {
            $from = $this->normalizeEmail((string) ($row['from'] ?? ''));
            if ($from === '' || ! in_array($from, $emails, true)) {
                continue;
            }

            try {
                $this->client->moveMessage($account, 'INBOX', (int) ($row['uid'] ?? 0), $junkPath);
                $moved++;
            } catch (\Throwable $e) {
                Log::warning('mail.sender_rule_move_failed', [
                    'account_id' => $account->id,
                    'uid' => $row['uid'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $moved;
    }

    public function normalizeEmail(string $email): string
    {
        $email = trim(Str::lower($email));
        if ($email === '') {
            return '';
        }

        if (preg_match('/<([^>]+)>/', $email, $m)) {
            $email = trim($m[1]);
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }
}
