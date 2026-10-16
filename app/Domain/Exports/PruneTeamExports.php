<?php

namespace App\Domain\Exports;

use App\Models\TeamExport;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Deletes team exports, and their archives, once `expires_at` has passed (API_SPEC.md §13: "automatically
 * removed after 24 hours"; ADR-024 §4). Run hourly by the scheduler (`exports:prune`).
 *
 * - **Expired rows**, whatever their state, in keyset batches: the file first, then the row (the
 *   delete repeats the expiry condition). An export is therefore gone at most an hour after it
 *   expires; until then the API already reports it `expired` and refuses the download.
 * - **Orphaned files**: an archive whose row no longer exists (its team was purged from the trash,
 *   or a crash between the two deletes) is removed too, so stored files never outlive their rows
 *   (DATABASE.md §13 purge rule for stored files).
 *
 * Idempotent; logs IDs only.
 *
 * @phpstan-type PruneResult array{exports: int, orphans: int}
 */
final class PruneTeamExports
{
    public const BATCH = 100;

    public function __construct(private readonly TeamExportStorage $storage) {}

    /**
     * @return PruneResult
     */
    public function handle(?CarbonInterface $now = null): array
    {
        $now ??= now();

        return [
            'exports' => $this->pruneExpired($now),
            'orphans' => $this->pruneOrphans(),
        ];
    }

    private function pruneExpired(CarbonInterface $now): int
    {
        $pruned = 0;
        $after = null;
        $disk = $this->storage->disk();

        do {
            $candidates = TeamExport::query()->where('expires_at', '<=', $now)->orderBy('id')->limit(self::BATCH);

            if ($after !== null) {
                $candidates->where('id', '>', $after);
            }

            $batch = $candidates->get(['id', 'team_id']);

            foreach ($batch as $export) {
                $after = $export->id;
                // Wherever a finished or half-written archive of this export would be.
                $disk->delete($this->storage->key($export));

                $deleted = TeamExport::query()->whereKey($export->id)->where('expires_at', '<=', $now)->delete();

                if ($deleted > 0) {
                    $pruned++;
                    Log::info('team_export.pruned', ['team_id' => $export->team_id, 'export_id' => $export->id]);
                }
            }
        } while ($batch->count() === self::BATCH);

        return $pruned;
    }

    private function pruneOrphans(): int
    {
        $disk = $this->storage->disk();
        $candidates = [];

        foreach ($disk->allFiles() as $path) {
            if (preg_match(TeamExportStorage::KEY_PATTERN, $path, $match) === 1) {
                $candidates[$match[2]] = $path;
            }
        }

        $orphans = 0;

        foreach (array_chunk($candidates, 500, true) as $chunk) {
            $known = [];

            foreach (TeamExport::query()->whereIn('id', array_keys($chunk))->get(['id']) as $export) {
                $known[$export->id] = true;
            }

            foreach (array_diff_key($chunk, $known) as $id => $path) {
                $disk->delete($path);
                $orphans++;
                Log::info('team_export.orphan_pruned', ['export_id' => $id]);
            }
        }

        return $orphans;
    }
}
