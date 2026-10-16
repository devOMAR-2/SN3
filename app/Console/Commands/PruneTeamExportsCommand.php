<?php

namespace App\Console\Commands;

use App\Domain\Exports\PruneTeamExports;
use Illuminate\Console\Command;

/**
 * `php artisan exports:prune`: deletes team exports and their archives 24 hours after they complete
 * (API_SPEC.md §13, ADR-024 §4), plus archives whose export row is gone and stale work
 * directories. Scheduled hourly in routes/console.php; safe to run by hand or twice.
 */
class PruneTeamExportsCommand extends Command
{
    /** @var string */
    protected $signature = 'exports:prune';

    /** @var string */
    protected $description = 'Delete expired team exports and their archives';

    public function handle(PruneTeamExports $prune): int
    {
        $pruned = $prune->handle();

        $this->info(sprintf(
            'Pruned %d expired export(s), %d orphaned archive(s) and %d stale work director(ies).',
            $pruned['exports'],
            $pruned['orphans'],
            $pruned['work_dirs'],
        ));

        return self::SUCCESS;
    }
}
