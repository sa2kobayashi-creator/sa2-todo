<?php

namespace App\Services;

use App\Models\MailAccount;
use App\Models\MailMessageHeader;

class MailActionService
{
    public const BULK_LIMIT = 50;

    public function __construct(
        private MailClientService $client,
        private MailSenderRuleService $senderRules,
    ) {}

    /**
     * @param  list<int>  $uids
     * @return array{ok: int, failed: list<int>}
     */
    public function bulk(
        MailAccount $account,
        string $folder,
        array $uids,
        string $action,
        ?string $targetFolder = null,
        bool $registerSender = false,
    ): array {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        $uids = array_values(array_filter($uids, fn (int $u) => $u > 0));

        if ($uids === []) {
            throw new \InvalidArgumentException(__('メールが選択されていません。'));
        }

        if (count($uids) > self::BULK_LIMIT) {
            throw new \InvalidArgumentException(__('一度に操作できるのは :max 件までです。', ['max' => self::BULK_LIMIT]));
        }

        $folder = $folder !== '' ? $folder : 'INBOX';
        $ok = 0;
        $failed = [];

        foreach ($uids as $uid) {
            try {
                $this->singleAction($account, $folder, $uid, $action, $targetFolder, $registerSender);
                $ok++;
            } catch (\Throwable) {
                $failed[] = $uid;
            }
        }

        return ['ok' => $ok, 'failed' => $failed];
    }

    public function toggleStar(MailAccount $account, string $folder, int $uid, bool $flagged): void
    {
        $folder = $folder !== '' ? $folder : 'INBOX';
        $this->client->setMessageFlag($account, $folder, $uid, 'Flagged', $flagged);

        if ($folder === 'INBOX' && $account->is_sa2_plus_mailbox) {
            MailMessageHeader::query()
                ->where('mail_account_id', $account->id)
                ->where('folder_path', 'INBOX')
                ->where('imap_uid', $uid)
                ->update(['is_flagged' => $flagged]);
        }
    }

    private function singleAction(
        MailAccount $account,
        string $folder,
        int $uid,
        string $action,
        ?string $targetFolder,
        bool $registerSender,
    ): void {
        switch ($action) {
            case 'read':
                $this->client->setMessageFlag($account, $folder, $uid, 'Seen', true);
                $this->touchInboxHeader($account, $folder, $uid, ['is_seen' => true]);

                return;

            case 'unread':
                $this->client->setMessageFlag($account, $folder, $uid, 'Seen', false);
                $this->touchInboxHeader($account, $folder, $uid, ['is_seen' => false]);

                return;

            case 'delete':
                $dest = $account->is_sa2_plus_mailbox
                    ? $this->client->appSystemFolderPath('trash')
                    : 'Trash';
                $this->client->moveMessage($account, $folder, $uid, $dest ?? 'Trash');
                $this->removeInboxHeader($account, $folder, $uid);

                return;

            case 'archive':
                if (! $account->is_sa2_plus_mailbox) {
                    throw new \InvalidArgumentException(__('アーカイブは独自ドメインメールのみ利用できます。'));
                }
                $dest = $this->client->appSystemFolderPath('archive');
                if ($dest === null) {
                    throw new \RuntimeException(__('アーカイブフォルダが利用できません。'));
                }
                $this->client->moveMessage($account, $folder, $uid, $dest);
                $this->removeInboxHeader($account, $folder, $uid);

                return;

            case 'spam':
                if (! $account->is_sa2_plus_mailbox) {
                    throw new \InvalidArgumentException(__('迷惑メール操作は独自ドメインメールのみ利用できます。'));
                }
                $dest = $this->client->appSystemFolderPath('junk');
                if ($dest === null) {
                    throw new \RuntimeException(__('迷惑メールフォルダが利用できません。'));
                }
                if ($registerSender) {
                    $from = $this->client->getMessageFromEmail($account, $folder, $uid);
                    if ($from !== '') {
                        $this->senderRules->addRule($account, $from);
                    }
                }
                $this->client->moveMessage($account, $folder, $uid, $dest);
                $this->removeInboxHeader($account, $folder, $uid);

                return;

            case 'not_spam':
                if (! $account->is_sa2_plus_mailbox) {
                    throw new \InvalidArgumentException(__('迷惑メール操作は独自ドメインメールのみ利用できます。'));
                }
                $from = $this->client->getMessageFromEmail($account, $folder, $uid);
                if ($from !== '') {
                    $this->senderRules->removeRule($account, $from);
                }
                $this->client->moveMessage($account, $folder, $uid, 'INBOX');
                $this->removeInboxHeader($account, $folder, $uid);

                return;

            case 'move':
                if ($targetFolder === null || trim($targetFolder) === '') {
                    throw new \InvalidArgumentException(__('移動先フォルダを指定してください。'));
                }
                $this->client->moveMessage($account, $folder, $uid, trim($targetFolder));
                $this->removeInboxHeader($account, $folder, $uid);

                return;

            default:
                throw new \InvalidArgumentException(__('不明な操作です。'));
        }
    }

    /** @param  array<string, mixed>  $attrs */
    private function touchInboxHeader(MailAccount $account, string $folder, int $uid, array $attrs): void
    {
        if ($folder !== 'INBOX' || ! $account->is_sa2_plus_mailbox) {
            return;
        }

        MailMessageHeader::query()
            ->where('mail_account_id', $account->id)
            ->where('folder_path', 'INBOX')
            ->where('imap_uid', $uid)
            ->update($attrs);
    }

    private function removeInboxHeader(MailAccount $account, string $folder, int $uid): void
    {
        if ($folder !== 'INBOX' || ! $account->is_sa2_plus_mailbox) {
            return;
        }

        MailMessageHeader::query()
            ->where('mail_account_id', $account->id)
            ->where('folder_path', 'INBOX')
            ->where('imap_uid', $uid)
            ->delete();
    }
}
