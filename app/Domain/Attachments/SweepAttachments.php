<?php

namespace App\Domain\Attachments;

use App\Models\TaskAttachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\StorageAttributes;
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
 * Every disk attachments may be on is swept (AttachmentStorage::diskNames(): the configured one, the
 * previous ones an operator listed, and every disk a row still names), and its tree is walked lazily.
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
        $deleted = 0;

        foreach ($this->storage->diskNames() as $diskName) {
            $driver = $this->storage->disk($diskName)->getDriver();

            foreach ($this->directoryNames($driver->listContents(AttachmentStorage::PREFIX, false)) as $teamId) {
                if (! DB::table('teams')->where('id', $teamId)->exists()) {
                    $deleted += $this->files->purgeTeam($teamId) ? 1 : 0;

                    continue;
                }

                foreach ($this->directoryNames($driver->listContents($this->storage->teamPrefix($teamId), false)) as $projectId) {
                    if (! DB::table('projects')->where('id', $projectId)->where('team_id', $teamId)->exists()) {
                        $deleted += $this->files->purgeProject($teamId, $projectId) ? 1 : 0;
                    }
                }
            }
        }

        return $deleted;
    }

    /**
     * Walks each disk's `objects/` tree lazily (a listing, never the whole tree in memory) and checks
     * the files against the rows of that disk in batches.
     */
    private function deleteOrphans(): int
    {
        $cutoff = now()->subMinutes(self::ORPHAN_GRACE_MINUTES)->getTimestamp();
        $deleted = 0;

        foreach ($this->storage->diskNames() as $diskName) {
            $disk = $this->storage->disk($diskName);
            $batch = [];

            foreach ($disk->getDriver()->listContents(AttachmentStorage::PREFIX, true) as $item) {
                if ($item instanceof FileAttributes && preg_match(AttachmentStorage::KEY_PATTERN, $item->path()) === 1) {
                    $batch[$item->path()] = $item->lastModified();
                }

                if (count($batch) >= self::BATCH) {
                    $deleted += $this->deleteUnknown($diskName, $batch, $cutoff);
                    $batch = [];
                }
            }

            $deleted += $this->deleteUnknown($diskName, $batch, $cutoff);
        }

        return $deleted;
    }

    /**
     * @param  array<string, int|null>  $files  key => last modified (null when the listing has none)
     */
    private function deleteUnknown(string $diskName, array $files, int $cutoff): int
    {
        if ($files === []) {
            return 0;
        }

        $known = [];

        foreach (DB::table('task_attachments')->where('storage_disk', $diskName)->whereIn('storage_key', array_keys($files))->pluck('storage_key') as $key) {
            if (is_string($key)) {
                $known[$key] = true;
            }
        }

        $disk = $this->storage->disk($diskName);
        $deleted = 0;

        foreach ($files as $key => $modified) {
            if (isset($known[$key])) {
                continue;
            }

            try {
                if (($modified ?? $disk->lastModified($key)) <= $cutoff) {
                    $disk->delete($key);
                    $deleted++;
                    Log::info('attachment.orphan_deleted', ['disk' => $diskName, 'storage_key_prefix' => dirname($key)]);
                }
            } catch (Throwable) {
                // Gone meanwhile, or the storage is unavailable: the next run tries again.
            }
        }

        return $deleted;
    }

    /**
     * The names of the directories in a listing that are UUIDs.
     *
     * @param  iterable<StorageAttributes>  $listing
     * @return list<string>
     */
    private function directoryNames(iterable $listing): array
    {
        $names = [];

        foreach ($listing as $item) {
            $name = basename($item->path());

            if ($item instanceof DirectoryAttributes && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $name) === 1) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
