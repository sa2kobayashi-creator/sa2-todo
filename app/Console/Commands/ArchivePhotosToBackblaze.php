<?php

namespace App\Console\Commands;

use App\Services\PhotoColdArchiveService;
use Illuminate\Console\Command;

class ArchivePhotosToBackblaze extends Command
{
    protected $signature = 'photos:archive-cold {--limit=40 : Max photos to archive per run}';

    protected $description = 'Move old hot photos/videos from primary disk to Backblaze B2';

    public function handle(PhotoColdArchiveService $archive): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $this->info("Archiving up to {$limit} photo(s)...");

        $stats = $archive->archiveDuePhotos($limit);

        $this->info("archived={$stats['archived']} skipped={$stats['skipped']} errors={$stats['errors']}");

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
