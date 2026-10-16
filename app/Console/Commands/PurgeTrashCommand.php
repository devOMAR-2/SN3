<?php

namespace App\Console\Commands;

use App\Domain\Trash\PurgeTrash;
use Illuminate\Console\Command;

/**
 * `php artisan trash:purge`: hard-deletes teams and projects trashed more than 30 days ago
 * (DECIDED-011, ADR-022 §5). Scheduled daily in routes/console.php; safe to run by hand or twice.
 */
class PurgeTrashCommand extends Command
{
    /** @var string */
    protected $signature = 'trash:purge';

    /** @var string */
    protected $description = 'Permanently delete teams and projects that have been in the trash for 30 days';

    public function handle(PurgeTrash $purge): int
    {
        $purged = $purge->handle();

        $this->info(sprintf('Purged %d project(s) and %d team(s) from the trash.', $purged['projects'], $purged['teams']));

        return self::SUCCESS;
    }
}
