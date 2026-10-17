<?php

namespace App\Console\Commands;

use App\Domain\Attachments\SweepAttachments;
use Illuminate\Console\Command;

/**
 * `php artisan attachments:sweep`: finishes attachment removals the storage could not complete,
 * deletes the files of purged projects and teams that are still there, and removes orphaned files
 * (CORE-008, ADR-027 §5). Scheduled hourly in routes/console.php; safe to run by hand or twice.
 */
class SweepAttachmentsCommand extends Command
{
    /** @var string */
    protected $signature = 'attachments:sweep';

    /** @var string */
    protected $description = 'Delete attachment files that no attachment needs any more';

    public function handle(SweepAttachments $sweep): int
    {
        $swept = $sweep->handle();

        $this->info(sprintf(
            'Finished %d pending removal(s), deleted %d purged prefix(es) and %d orphaned file(s).',
            $swept['pending'],
            $swept['prefixes'],
            $swept['orphans'],
        ));

        return self::SUCCESS;
    }
}
