<?php

namespace App\Console\Commands;

use App\Services\MediaStorageConfigService;
use App\Services\PhotoColdArchiveService;
use Illuminate\Console\Command;

class ArchivePhotosToBackblaze extends Command
{
    protected $signature = 'photos:archive-cold {--limit=40 : Max photos to archive per run}';

    protected $description = 'Move old hot photos/videos from primary disk to Backblaze B2';

    public function handle(PhotoColdArchiveService $archive, MediaStorageConfigService $mediaConfig): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $mode = $mediaConfig->capacityMode();
        $this->info("Archiving up to {$limit} photo(s)... mode={$mode}");

        if (! $mediaConfig->backblazeEnabled()) {
            $this->warn('Backblaze is disabled; nothing to do.');
        }
        if (! $mediaConfig->pipelineArchivesToBackblaze()) {
            $this->warn('Pipeline archive is not enabled; nothing to do.');
        }
        if ($mode !== MediaStorageConfigService::CAPACITY_MODE_R2_CAP) {
            $this->warn('Tip: capacity mode is not r2_cap. Mode 1 is required to push R2 under the free cap.');
        }

        $stats = $archive->archiveDuePhotos($limit);

        $this->info("archived={$stats['archived']} skipped={$stats['skipped']} errors={$stats['errors']}");

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
