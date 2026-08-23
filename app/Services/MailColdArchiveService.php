<?php

namespace App\Services;

use App\Models\MailAccount;
use App\Models\MailArchive;
use App\Models\MailMessageHeader;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MailColdArchiveService
{
    public const FOLDER_PATH = 'SA2.B2';

    private const SOURCE_FOLDER = 'INBOX';

    public function __construct(
        private MailClientService $client,
        private MediaStorageConfigService $media,
    ) {}

    public static function isVirtualFolder(string $folder): bool
    {
        return strcasecmp(str_replace('/', '.', trim($folder)), self::FOLDER_PATH) === 0;
    }

    /**
     * @return array{archived: int, skipped: int, errors: int, reason: string}
     */
    public function archiveOverQuotaBoxes(?int $usedBytesOverride = null): array
    {
        if (! $this->media->backblazeEnabled()) {
            return ['archived' => 0, 'skipped' => 0, 'errors' => 0, 'reason' => 'b2_disabled'];
        }

        $this->media->applyRuntimeDisks();
        $startAt = (int) config('storage_management.mail_archive_bytes');
        $target = (int) config('storage_management.mail_target_bytes');
        $batch = max(1, (int) config('storage_management.mail_batch'));
        $accountLimit = max(1, (int) config('storage_management.mail_accounts_per_run'));
        $cutoff = now()->subDays(max(1, (int) config('storage_management.mail_protect_days')));

        $archived = 0;
        $skipped = 0;
        $errors = 0;
        $examined = 0;

        $accounts = MailAccount::query()
            ->where('is_sa2_plus_mailbox', true)
            ->orderBy('id')
            ->get();

        foreach ($accounts as $account) {
            if ($examined >= $accountLimit) {
                break;
            }

            $used = $usedBytesOverride;
            if ($used === null) {
                try {
                    $used = $this->client->mailboxUsedBytes($account);
                } catch (\Throwable $e) {
                    Log::warning('mail.cold_archive.quota_failed', [
                        'account_id' => $account->id,
                        'error' => $e->getMessage(),
                    ]);
                    $used = null;
                }
            }
            if ($used === null || $used < $startAt) {
                continue;
            }
            $examined++;

            try {
                $oldest = $this->client->oldestMessages($account, self::SOURCE_FOLDER, $batch * 2);
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('mail.cold_archive.list_failed', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $result = $this->processAccount($account, $oldest, $cutoff, $batch, $used, $target);
            $archived += $result['archived'];
            $skipped += $result['skipped'];
            $errors += $result['errors'];
        }

        return [
            'archived' => $archived,
            'skipped' => $skipped,
            'errors' => $errors,
            'reason' => $archived === 0 && $examined === 0 ? 'within_quota' : '',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $oldest
     * @return array{archived: int, skipped: int, errors: int}
     */
    private function processAccount(
        MailAccount $account,
        array $oldest,
        \Illuminate\Support\Carbon $cutoff,
        int $batch,
        int $used,
        int $target,
    ): array {
        $skipped = 0;
        $candidates = [];

        foreach ($oldest as $row) {
            if (count($candidates) >= $batch) {
                break;
            }
            if (! empty($row['flagged'])) {
                $skipped++;

                continue;
            }
            // 日付が読めないメールは保護期間の判定ができないので触らない。
            if (empty($row['date']) || $row['date']->greaterThan($cutoff)) {
                $skipped++;

                continue;
            }
            $candidates[] = $row;
            $used = max(0, $used - max(0, (int) ($row['size'] ?? 0)));
            if ($used <= $target) {
                break;
            }
        }

        if ($candidates === []) {
            return ['archived' => 0, 'skipped' => $skipped, 'errors' => 0];
        }

        $uids = array_map(fn ($row) => (int) $row['uid'], $candidates);

        // 前回 B2 保存後に IMAP 削除だけ失敗したものは、退避し直さず削除だけ再試行する。
        $archivedRows = MailArchive::query()
            ->where('mail_account_id', $account->id)
            ->where('folder_path', self::SOURCE_FOLDER)
            ->whereIn('imap_uid', $uids)
            ->get();

        $alreadyArchived = $archivedRows->pluck('imap_uid')->map(fn ($v) => (int) $v)->all();

        // DB 行だけを根拠に IMAP から消さない。B2 に実体があることを毎回確かめる。
        $disk = (string) config('storage_management.disk', 'backblaze');
        $verified = [];
        foreach ($archivedRows as $archivedRow) {
            try {
                if (Storage::disk($disk)->exists((string) $archivedRow->storage_key)) {
                    $verified[] = (int) $archivedRow->imap_uid;

                    continue;
                }
            } catch (\Throwable $e) {
                Log::warning('mail.cold_archive.verify_failed', [
                    'account_id' => $account->id,
                    'uid' => $archivedRow->imap_uid,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }
            Log::warning('mail.cold_archive.missing_object', [
                'account_id' => $account->id,
                'uid' => $archivedRow->imap_uid,
                'key' => $archivedRow->storage_key,
            ]);
        }

        $pending = array_values(array_filter($candidates, fn ($row) => ! in_array((int) $row['uid'], $alreadyArchived, true)));

        $errors = 0;
        $archived = 0;
        $deletable = $verified;

        $raws = [];
        if ($pending !== []) {
            try {
                $raws = $this->client->exportRawMessages(
                    $account,
                    self::SOURCE_FOLDER,
                    array_map(fn ($row) => (int) $row['uid'], $pending)
                );
            } catch (\Throwable $e) {
                Log::warning('mail.cold_archive.export_failed', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);

                return ['archived' => 0, 'skipped' => $skipped, 'errors' => 1];
            }
        }

        foreach ($pending as $row) {
            $uid = (int) $row['uid'];
            $raw = $raws[$uid] ?? '';
            if ($raw === '') {
                $errors++;

                continue;
            }
            try {
                $this->storeArchive($account, $row, $raw);
                $deletable[] = $uid;
                $archived++;
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('mail.cold_archive.item_failed', [
                    'account_id' => $account->id,
                    'uid' => $uid,
                    'error' => $e->getMessage(),
                ]);
                if ($errors >= (int) config('storage_management.max_consecutive_errors')) {
                    break;
                }
            }
        }

        if ($deletable !== []) {
            try {
                $deleted = $this->client->expungeMessages($account, self::SOURCE_FOLDER, $deletable);
                $this->forgetHeaders($account, $deleted);
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('mail.cold_archive.expunge_failed', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['archived' => $archived, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * 1件だけ退避する（手動実行・再試行用）。
     *
     * @param  array<string, mixed>  $row
     */
    public function archiveOne(MailAccount $account, array $row): MailArchive
    {
        $this->media->applyRuntimeDisks();
        $raw = $this->client->exportRawMessage($account, self::SOURCE_FOLDER, (int) $row['uid']);
        if ($raw === '') {
            throw new \RuntimeException('empty eml');
        }

        $archive = $this->storeArchive($account, $row, $raw);
        $deleted = $this->client->expungeMessages($account, self::SOURCE_FOLDER, [(int) $row['uid']]);
        $this->forgetHeaders($account, $deleted);

        return $archive;
    }

    /**
     * B2 への保存が確認できてから DB 行を作る。順序を逆にすると put 失敗時に
     * 実体のないアーカイブ行が残る。
     *
     * @param  array<string, mixed>  $row
     */
    private function storeArchive(MailAccount $account, array $row, string $raw): MailArchive
    {
        $disk = (string) config('storage_management.disk', 'backblaze');
        $key = sprintf(
            'archives/mail/%d/%d/%s/%s/%d-%s.eml',
            $account->user_id,
            $account->id,
            now()->format('Y'),
            now()->format('m'),
            (int) $row['uid'],
            Str::lower(Str::random(8))
        );

        Storage::disk($disk)->put($key, $raw);
        if (! Storage::disk($disk)->exists($key)) {
            throw new \RuntimeException('b2 put failed');
        }

        try {
            return MailArchive::query()->create([
                'user_id' => $account->user_id,
                'mail_account_id' => $account->id,
                'folder_path' => self::SOURCE_FOLDER,
                'imap_uid' => (int) $row['uid'],
                'message_id' => $this->extractHeader($raw, 'Message-ID'),
                'subject' => mb_substr((string) ($row['subject'] ?? ''), 0, 998),
                'from_address' => mb_substr((string) ($row['from'] ?? ''), 0, 512),
                'to_address' => mb_substr((string) ($row['to'] ?? ''), 0, 512),
                'sent_at' => $row['date'] ?? null,
                'size_bytes' => max(strlen($raw), (int) ($row['size'] ?? 0)),
                'has_attachments' => str_contains(strtolower($raw), 'content-disposition: attachment'),
                'storage_provider' => 'b2',
                'storage_key' => $key,
                'archived_at' => now(),
            ]);
        } catch (\Throwable $e) {
            try {
                Storage::disk($disk)->delete($key);
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    /**
     * @param  list<int>  $uids
     */
    private function forgetHeaders(MailAccount $account, array $uids): void
    {
        if ($uids === []) {
            return;
        }
        MailMessageHeader::query()
            ->where('mail_account_id', $account->id)
            ->where('folder_path', self::SOURCE_FOLDER)
            ->whereIn('imap_uid', $uids)
            ->delete();
    }

    /**
     * @return list<array{uid: int, subject: string, from: string, date: ?string, seen: bool, flagged: bool}>
     */
    public function page(MailAccount $account, int $page = 1, int $perPage = 30): array
    {
        return MailArchive::query()
            ->where('mail_account_id', $account->id)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->forPage(max(1, $page), $perPage)
            ->get()
            ->map(fn (MailArchive $row) => [
                'uid' => (int) $row->id,
                'subject' => $row->subject ?: __('(件名なし)'),
                'from' => (string) $row->from_address,
                'date' => $row->sent_at?->toIso8601String(),
                'seen' => true,
                'flagged' => false,
            ])
            ->all();
    }

    public function count(MailAccount $account): int
    {
        return MailArchive::query()->where('mail_account_id', $account->id)->count();
    }

    /**
     * @return array{uid: int, subject: string, from: string, to: string, date: ?string, bodyHtml: string, bodyText: string}
     */
    public function read(MailAccount $account, int $archiveId): array
    {
        $row = MailArchive::query()
            ->where('mail_account_id', $account->id)
            ->whereKey($archiveId)
            ->firstOrFail();

        $this->media->applyRuntimeDisks();
        $raw = (string) Storage::disk((string) config('storage_management.disk', 'backblaze'))->get($row->storage_key);
        $text = $this->plainTextFromEml($raw);

        return [
            'uid' => (int) $row->id,
            'subject' => $row->subject ?: __('(件名なし)'),
            'from' => (string) $row->from_address,
            'to' => (string) $row->to_address,
            'date' => $row->sent_at?->toIso8601String(),
            'bodyHtml' => '',
            'bodyText' => $text !== '' ? $text : __('（長期保存。本文は原本から抽出できませんでした）'),
        ];
    }

    private function extractHeader(string $raw, string $name): ?string
    {
        if (preg_match('/^'.preg_quote($name, '/').':\s*(.+)$/im', $raw, $m) === 1) {
            return mb_substr(trim($m[1]), 0, 512);
        }

        return null;
    }

    private function plainTextFromEml(string $raw): string
    {
        $parts = preg_split("/\r\n\r\n|\n\n/", $raw, 2);
        $body = $parts[1] ?? '';
        $body = preg_replace('/--[^\n]+/', '', $body) ?? $body;
        $body = preg_replace('/Content-[^\n]+\n/i', '', $body) ?? $body;

        return mb_substr(trim(html_entity_decode(strip_tags($body))), 0, 20000);
    }
}
