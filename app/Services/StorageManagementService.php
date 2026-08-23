<?php

namespace App\Services;

use App\Models\StorageManagementLog;
use Illuminate\Support\Facades\Log;

class StorageManagementService
{
    public function __construct(
        private StorageUsageService $usage,
        private MailColdArchiveService $mailArchive,
        private PhotoColdArchiveService $photoArchive,
        private DatabaseRecordArchiveService $dbArchive,
        private MediaStorageConfigService $media,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $snap = $this->usage->snapshot();
        $mail = ['archived' => 0, 'skipped' => 0, 'errors' => 0, 'reason' => ''];
        $photos = ['archived' => 0, 'reason' => ''];
        $db = ['archived' => 0, 'skipped' => 0, 'errors' => 0, 'reason' => ''];
        $notes = [];

        if ($snap['mail_status'] === 'archive') {
            $mail = $this->mailArchive->archiveOverQuotaBoxes();
        } elseif ($snap['mail_status'] === 'ok' && $snap['mail_bytes'] === 0 && $snap['mail_boxes'] === []) {
            $notes[] = 'no_sa2_mailboxes';
        }

        $r2Start = (int) config('storage_management.r2_archive_bytes');
        if ($snap['r2_bytes'] >= $r2Start && $this->media->backblazeEnabled()) {
            try {
                $result = $this->photoArchive->archiveDuePhotos((int) config('photos.archive_cold_batch_size', 8));
                $photos = [
                    'archived' => (int) ($result['archived'] ?? 0),
                    'reason' => (string) ($result['reason'] ?? ''),
                ];
            } catch (\Throwable $e) {
                Log::warning('storage.manage.photos_failed', ['error' => $e->getMessage()]);
                $photos['reason'] = $e->getMessage();
            }
        }

        if ($snap['db_status'] === 'archive') {
            $db = $this->dbArchive->archiveDueRecords();
            if (($db['reason'] ?? '') === 'no_due_records') {
                $notes[] = 'db_needs_user_cleanup';
            }
        }

        $error = null;
        if (($mail['errors'] ?? 0) > 0 || ($db['errors'] ?? 0) > 0) {
            $error = 'partial';
        }

        $log = StorageManagementLog::query()->create([
            'ran_at' => now(),
            'r2_bytes' => $snap['r2_bytes'],
            'mail_bytes' => $snap['mail_bytes'],
            'db_bytes' => $snap['db_bytes'],
            'r2_status' => $snap['r2_status'],
            'mail_status' => $snap['mail_status'],
            'db_status' => $snap['db_status'],
            'mail_archived' => (int) ($mail['archived'] ?? 0),
            'photos_archived' => (int) ($photos['archived'] ?? 0),
            'db_archived' => (int) ($db['archived'] ?? 0),
            'notes' => $notes,
            'error' => $error,
        ]);

        return [
            'log_id' => $log->id,
            'usage' => $snap,
            'mail' => $mail,
            'photos' => $photos,
            'db' => $db,
        ];
    }
}
