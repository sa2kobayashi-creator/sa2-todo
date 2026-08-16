<?php

namespace App\Services;

use App\Models\MailAccount;
use App\Models\MailMessageHeader;
use Illuminate\Support\Facades\Log;

class MailMetadataSyncService
{
    private const KEEP_PER_FOLDER = 500;

    public function __construct(private MailClientService $client) {}

    /**
     * @return array{synced: int, exists: int}
     */
    public function syncInbox(MailAccount $account): array
    {
        try {
            $snapshot = $this->client->mailboxSnapshot($account, 'INBOX', 1, 0, 50, true);
            $rows = $snapshot['messages'];
            $now = now();

            foreach ($rows as $row) {
                $uid = (int) ($row['uid'] ?? 0);
                if ($uid < 1) {
                    continue;
                }

                MailMessageHeader::query()->updateOrCreate(
                    [
                        'mail_account_id' => $account->id,
                        'folder_path' => 'INBOX',
                        'imap_uid' => $uid,
                    ],
                    [
                        'subject' => mb_substr((string) ($row['subject'] ?? ''), 0, 998),
                        'from_address' => mb_substr((string) ($row['from'] ?? ''), 0, 512),
                        'received_at' => filled($row['date'] ?? null) ? $row['date'] : null,
                        'is_seen' => (bool) ($row['seen'] ?? false),
                        'synced_at' => $now,
                    ]
                );
            }

            $this->prune($account, 'INBOX');
            $account->forceFill([
                'last_synced_at' => $now,
                'last_sync_status' => 'ok',
                'last_sync_message' => __('同期済み（:count 件）', ['count' => count($rows)]),
            ])->save();

            return ['synced' => count($rows), 'exists' => (int) ($snapshot['folders'][0]['messages'] ?? count($rows))];
        } catch (\Throwable $e) {
            Log::warning('mail.metadata_sync_failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            $account->forceFill([
                'last_synced_at' => now(),
                'last_sync_status' => 'fail',
                'last_sync_message' => mb_substr($e->getMessage(), 0, 480),
            ])->save();

            throw $e;
        }
    }

    /**
     * @return list<array{uid: int, subject: string, from: string, date: ?string, seen: bool}>
     */
    public function page(MailAccount $account, string $folderPath = 'INBOX', int $page = 1, int $perPage = 30): array
    {
        return MailMessageHeader::query()
            ->where('mail_account_id', $account->id)
            ->where('folder_path', $folderPath)
            ->orderByDesc('received_at')
            ->orderByDesc('imap_uid')
            ->forPage(max(1, $page), $perPage)
            ->get()
            ->map(fn (MailMessageHeader $header) => [
                'uid' => (int) $header->imap_uid,
                'subject' => $header->subject ?: __('(件名なし)'),
                'from' => $header->from_address ?: '',
                'date' => $header->received_at?->toIso8601String(),
                'seen' => $header->is_seen,
            ])
            ->all();
    }

    public function count(MailAccount $account, string $folderPath = 'INBOX'): int
    {
        return MailMessageHeader::query()
            ->where('mail_account_id', $account->id)
            ->where('folder_path', $folderPath)
            ->count();
    }

    private function prune(MailAccount $account, string $folderPath): void
    {
        // MySQL は OFFSET 単独が使えず LIMIT 必須のため、残す ID を先に取る
        $keepIds = MailMessageHeader::query()
            ->where('mail_account_id', $account->id)
            ->where('folder_path', $folderPath)
            ->orderByDesc('received_at')
            ->orderByDesc('imap_uid')
            ->limit(self::KEEP_PER_FOLDER)
            ->pluck('id');

        $query = MailMessageHeader::query()
            ->where('mail_account_id', $account->id)
            ->where('folder_path', $folderPath);

        if ($keepIds->isNotEmpty()) {
            $query->whereNotIn('id', $keepIds);
        }

        $query->delete();
    }
}
