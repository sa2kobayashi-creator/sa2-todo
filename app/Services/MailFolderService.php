<?php

namespace App\Services;

use App\Models\MailAccount;
use App\Models\MailFolder;

class MailFolderService
{
    /** Lolipop 等はルート直下に作れないため INBOX 配下に置く */
    public const PATH_PREFIX = 'INBOX.Folders.';

    public function __construct(
        private MailClientService $client,
    ) {}

    /** @return list<MailFolder> */
    public function listForAccount(MailAccount $account): array
    {
        return MailFolder::query()
            ->where('mail_account_id', $account->id)
            ->orderBy('name')
            ->get()
            ->all();
    }

    public function create(MailAccount $account, string $name): MailFolder
    {
        if (! $account->is_sa2_plus_mailbox) {
            throw new \InvalidArgumentException(__('ユーザーフォルダは独自ドメインメールのみ利用できます。'));
        }

        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException(__('フォルダ名を入力してください。'));
        }

        if (preg_match('/[\/\\\\]/', $name)) {
            throw new \InvalidArgumentException(__('フォルダ名に / や \\ は使えません。'));
        }

        $path = self::PATH_PREFIX.$name;

        $existing = MailFolder::query()
            ->where('mail_account_id', $account->id)
            ->where('name', $name)
            ->first();
        if ($existing) {
            return $existing;
        }

        $resolved = $this->client->ensureFolder($account, $path);

        return MailFolder::query()->create([
            'mail_account_id' => $account->id,
            'name' => $name,
            'folder_path' => $resolved !== '' ? $resolved : $path,
        ]);
    }

    public function delete(MailAccount $account, MailFolder $folder): void
    {
        if ((int) $folder->mail_account_id !== (int) $account->id) {
            throw new \InvalidArgumentException(__('フォルダが見つかりません。'));
        }

        $folder->delete();
    }
}
