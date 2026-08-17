<?php

namespace App\Services;

use App\Models\MailAccount;
use App\Models\MailLabel;
use App\Models\MailLabelRule;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MailLabelService
{
    public function __construct(private MailClientService $client) {}

    /** @return list<MailLabel> */
    public function listForAccount(MailAccount $account): array
    {
        $this->assertSa2Plus($account);

        return MailLabel::query()
            ->where('mail_account_id', $account->id)
            ->with('rules')
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $user, int $accountId, array $input): MailLabel
    {
        $account = $this->ownedSa2Plus($user, $accountId);
        $data = $this->validatedLabel($input);
        $folderPath = $this->folderPathFromName($data['name']);

        $exists = MailLabel::query()
            ->where('mail_account_id', $account->id)
            ->where(function ($q) use ($data, $folderPath) {
                $q->where('name', $data['name'])->orWhere('folder_path', $folderPath);
            })
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'name' => __('同じ名前のラベルがすでにあります。'),
            ]);
        }

        try {
            $this->client->ensureFolder($account, $folderPath);
        } catch (\Throwable $e) {
            Log::warning('mail.label_folder_create_failed', [
                'account_id' => $account->id,
                'folder' => $folderPath,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(__('ラベル用フォルダの作成に失敗しました。').' '.$e->getMessage(), 0, $e);
        }

        return MailLabel::create([
            'mail_account_id' => $account->id,
            'name' => $data['name'],
            'folder_path' => $folderPath,
            'color' => $data['color'],
        ])->load('rules');
    }

    public function delete(User $user, int $accountId, int $labelId): void
    {
        $account = $this->ownedSa2Plus($user, $accountId);
        $label = MailLabel::query()
            ->where('mail_account_id', $account->id)
            ->whereKey($labelId)
            ->first();
        if (! $label) {
            throw new \InvalidArgumentException(__('ラベルが見つかりません。'));
        }
        $label->delete();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function addRule(User $user, int $accountId, int $labelId, array $input): MailLabelRule
    {
        $account = $this->ownedSa2Plus($user, $accountId);
        $label = MailLabel::query()
            ->where('mail_account_id', $account->id)
            ->whereKey($labelId)
            ->first();
        if (! $label) {
            throw new \InvalidArgumentException(__('ラベルが見つかりません。'));
        }

        $data = $this->validatedRule($input);

        return MailLabelRule::create([
            'mail_label_id' => $label->id,
            'match_field' => $data['match_field'],
            'match_operator' => $data['match_operator'],
            'match_value' => $data['match_value'],
            'priority' => $data['priority'],
            'is_active' => true,
        ]);
    }

    public function deleteRule(User $user, int $accountId, int $ruleId): void
    {
        $account = $this->ownedSa2Plus($user, $accountId);
        $rule = MailLabelRule::query()
            ->whereKey($ruleId)
            ->whereHas('label', fn ($q) => $q->where('mail_account_id', $account->id))
            ->first();
        if (! $rule) {
            throw new \InvalidArgumentException(__('振り分けルールが見つかりません。'));
        }
        $rule->delete();
    }

    /**
     * INBOX 同期直後にルールを適用（IMAP移動を試みる）。
     *
     * @param  list<array{uid: int, subject: string, from: string, date: ?string, seen: bool}>  $messages
     */
    public function applyRulesToInbox(MailAccount $account, array $messages): int
    {
        if (! $account->is_sa2_plus_mailbox || $messages === []) {
            return 0;
        }

        $labels = MailLabel::query()
            ->where('mail_account_id', $account->id)
            ->with(['rules' => fn ($q) => $q->where('is_active', true)->orderBy('priority')->orderBy('id')])
            ->get();
        if ($labels->isEmpty()) {
            return 0;
        }

        $moved = 0;
        foreach ($messages as $row) {
            $target = $this->matchingFolder($labels, $row);
            if ($target === null || $target === 'INBOX') {
                continue;
            }
            try {
                $this->client->moveMessage($account, 'INBOX', (int) $row['uid'], $target);
                $moved++;
            } catch (\Throwable $e) {
                Log::warning('mail.label_move_failed', [
                    'account_id' => $account->id,
                    'uid' => $row['uid'] ?? null,
                    'target' => $target,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $moved;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, MailLabel>  $labels
     * @param  array{uid: int, subject: string, from: string, date: ?string, seen: bool}  $row
     */
    private function matchingFolder($labels, array $row): ?string
    {
        foreach ($labels as $label) {
            foreach ($label->rules as $rule) {
                if ($this->ruleMatches($rule, $row)) {
                    return $label->folder_path;
                }
            }
        }

        return null;
    }

    /**
     * @param  array{uid: int, subject: string, from: string, date: ?string, seen: bool}  $row
     */
    private function ruleMatches(MailLabelRule $rule, array $row): bool
    {
        $haystack = match ($rule->match_field) {
            'from' => (string) ($row['from'] ?? ''),
            'subject' => (string) ($row['subject'] ?? ''),
            'to' => '',
            default => '',
        };
        $needle = trim((string) $rule->match_value);
        if ($needle === '') {
            return false;
        }

        return match ($rule->match_operator) {
            'equals' => mb_strtolower($haystack) === mb_strtolower($needle),
            default => mb_stripos($haystack, $needle) !== false,
        };
    }

    private function ownedSa2Plus(User $user, int $accountId): MailAccount
    {
        $account = MailAccount::query()
            ->where('user_id', $user->id)
            ->whereKey($accountId)
            ->first();
        if (! $account) {
            throw new \InvalidArgumentException(__('メールアカウントが見つかりません。'));
        }
        $this->assertSa2Plus($account);

        return $account;
    }

    private function assertSa2Plus(MailAccount $account): void
    {
        if (! $account->is_sa2_plus_mailbox) {
            throw new \InvalidArgumentException(__('ラベルと自動振り分けは @:domain アカウントのみ利用できます。', [
                'domain' => config('mail_domain.domain'),
            ]));
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, color: string}
     */
    private function validatedLabel(array $input): array
    {
        $v = Validator::make($input, [
            'name' => ['required', 'string', 'max:80', 'regex:/^[\p{L}\p{N}][\p{L}\p{N} ._-]{0,78}$/u'],
            'color' => ['nullable', 'string', 'max:16', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);
        if ($v->fails()) {
            throw new ValidationException($v);
        }
        $data = $v->validated();

        return [
            'name' => trim($data['name']),
            'color' => $data['color'] ?? '#0f766e',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{match_field: string, match_operator: string, match_value: string, priority: int}
     */
    private function validatedRule(array $input): array
    {
        $v = Validator::make($input, [
            'match_field' => ['required', 'in:from,subject'],
            'match_operator' => ['nullable', 'in:contains,equals'],
            'match_value' => ['required', 'string', 'max:255'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);
        if ($v->fails()) {
            throw new ValidationException($v);
        }
        $data = $v->validated();

        return [
            'match_field' => $data['match_field'],
            'match_operator' => $data['match_operator'] ?? 'contains',
            'match_value' => trim($data['match_value']),
            'priority' => (int) ($data['priority'] ?? 100),
        ];
    }

    private function folderPathFromName(string $name): string
    {
        $safe = preg_replace('/[\\\\\/]+/u', '-', $name) ?? $name;

        return 'Labels.'.$safe;
    }
}
