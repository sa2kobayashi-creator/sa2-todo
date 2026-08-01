<?php

namespace App\Console\Commands;

use App\Services\MediaStorageConfigService;
use App\Services\PhotoColdArchiveService;
use Illuminate\Console\Command;

class ArchivePhotosToBackblaze extends Command
{
    protected $signature = 'photos:archive-cold
        {--limit=40 : Max photos to archive per batch}
        {--max-batches=50 : Stop after this many batches (safety valve)}';

    protected $description = 'Move old hot photos/videos from primary disk to Backblaze B2';

    public function handle(PhotoColdArchiveService $archive, MediaStorageConfigService $mediaConfig): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $maxBatches = max(1, (int) $this->option('max-batches'));
        $mode = $mediaConfig->capacityMode();
        $this->info("Archiving up to {$limit} photo(s) per batch (max {$maxBatches} batches)... mode={$mode}");

        if (! $mediaConfig->backblazeEnabled()) {
            $this->warn('Backblaze is disabled; nothing to do.');
        }
        if (! $mediaConfig->pipelineArchivesToBackblaze()) {
            $this->warn('Pipeline archive is not enabled; nothing to do.');
        }
        if ($mode !== MediaStorageConfigService::CAPACITY_MODE_R2_CAP) {
            $this->warn('Tip: capacity mode is not r2_cap. Mode 1 is required to push R2 under the free cap.');
        }

        $archived = 0;
        $skipped = 0;
        $errors = 0;
        $reason = '';
        $batch = 0;

        do {
            $batch++;
            $stats = $archive->archiveDuePhotos($limit);
            $archived += (int) $stats['archived'];
            $skipped += (int) $stats['skipped'];
            $errors += (int) $stats['errors'];
            $reason = (string) ($stats['reason'] ?? '');

            $this->line("batch {$batch}: archived={$stats['archived']} skipped={$stats['skipped']} errors={$stats['errors']}");
        } while (! empty($stats['hasMore']) && $batch < $maxBatches);

        $this->info("total archived={$archived} skipped={$skipped} errors={$errors}");

        if ($archived === 0 && $reason !== '') {
            $this->warn('Nothing moved: '.$this->reasonHint($reason, $mediaConfig));
        }
        if (! empty($stats['hasMore'])) {
            $this->warn('Batch cap reached; run again to continue.');
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function reasonHint(string $reason, MediaStorageConfigService $mediaConfig): string
    {
        return match ($reason) {
            PhotoColdArchiveService::REASON_DISABLED => 'Backblaze or the archive pipeline is disabled in storage settings.',
            PhotoColdArchiveService::REASON_WITHIN_QUOTA => 'Hot storage is already within the cap.',
            PhotoColdArchiveService::REASON_NO_DUE_PHOTOS => 'No photo is older than archive_after_days='.$mediaConfig->archiveAfterDays().'. Switch capacity mode to r2_cap to archive by size instead.',
            PhotoColdArchiveService::REASON_ALL_BLOCKED => 'Remaining originals could not be moved (missing source file or B2 write failure). Check the log.',
            default => $reason,
        };
    }
}
