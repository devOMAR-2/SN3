<?php

namespace App\Domain\Attachments;

use App\Models\TaskAttachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The safety net for stored attachment files (CORE-008, ADR-027 §5), run hourly as
 * `attachments:sweep`. Every request and the trash purge already delete their files; this catches
 * what an outage or a crash left behind:
 *
 * 1. **Pending removals:** rows marked `deleted_at` whose file could not be deleted are finished
 *    (file, then row).
 * 2. **Purged prefixes:** `objects/<team>/` and `objects/<team>/<project>/` directories whose team or
 *    project row no longer exists (the purge's own file deletion failed) are deleted.
 * 3. **Orphans:** files under a live project's prefix that no row references, older than
 *    ORPHAN_GRACE_MINUTES (so an upload between its write and its commit is never touched), are
 *    deleted: the leftovers of uploads whose transaction rolled back while the process died.
 *
 * Only keys under `objects/` that match AttachmentStorage::KEY_PATTERN are ever considered.
 */
final class SweepAttachments
{
    public const ORPHAN_GRACE_MINUTES = 60;

    private const BATCH = 500;

    public function __construct(
        private readonly AttachmentStorage $storage,
        private readonly AttachmentFiles $files,
    ) {}

    /**
     * @return array{pending: int, prefixes: int, orphans: int}
     */
    public function handle(): array
    {
        return [
            'pending' => $this->finishPending(),
            'prefixes' => $this->deletePurgedPrefixes(),
            'orphans' => $this->deleteOrphans(),
        ];
    }

    private function finishPending(): int
    {
        $finished = 0;

        foreach (TaskAttachment::query()->whereNotNull('deleted_at')->lazyById(self::BATCH) as $attachment) {
            if ($this->files->finish($attachment)) {
                $finished++;
            }
        }

        return $finished;
    }

    private function deletePurgedPrefixes(): int
    {
        $disk = $this->storage->disk();
        $deleted = 0;

        foreach ($this->uuidNames($disk->directories(AttachmentStorage::PREFIX)) as $teamId) {
            if (! DB::table('teams')->where('id', $teamId)->exists()) {
                $deleted += $this->files->purgeTeam($teamId) ? 1 : 0;

                continue;
            }

            foreach ($this->uuidNames($disk->directories($this->storage->teamPrefix($teamId))) as $projectId) {
                if (! DB::table('projects')->where('id', $projectId)->where('team_id', $teamId)->exists()) {
                    $deleted += $this->files->purgeProject($teamId, $projectId) ? 1 : 0;
                }
            }
        }

        return $deleted;
    }

    private function deleteOrphans(): int
    {
        $disk = $this->storage->disk();
        $cutoff = now()->subMinutes(self::ORPHAN_GRACE_MINUTES)->getTimestamp();
        $deleted = 0;

        foreach (array_chunk($disk->allFiles(AttachmentStorage::PREFIX), self::BATCH) as $keys) {
            $candidates = array_values(array_filter($keys, fn (string $key): bool => preg_match(AttachmentStorage::KEY_PATTERN, $key) === 1));

            if ($candidates === []) {
                continue;
            }

            $known = [];

            foreach (DB::table('task_attachments')->whereIn('storage_key', $candidates)->pluck('storage_key') as $key) {
                if (is_string($key)) {
                    $known[$key] = true;
                }
            }

            foreach ($candidates as $key) {
                if (isset($known[$key])) {
                    continue;
                }

                try {
                    if ($disk->lastModified($key) <= $cutoff) {
                        $disk->delete($key);
                        $deleted++;
                        Log::info('attachment.orphan_deleted', ['storage_key_prefix' => dirname($key)]);
                    }
                } catch (Throwable) {
                    // Gone meanwhile, or the storage is unavailable: the next run tries again.
                }
            }
        }

        return $deleted;
    }

    /**
     * The last path segments that are UUIDs.
     *
     * @param  array<int, string>  $paths
     * @return list<string>
     */
    private function uuidNames(array $paths): array
    {
        $names = [];

        foreach ($paths as $path) {
            $name = basename($path);

            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $name) === 1) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
