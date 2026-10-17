<?php

namespace App\Domain\Attachments;

use App\Models\TaskAttachment;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Where attachment files live (API_SPEC.md §6.3, ARCHITECTURE.md §5.3; CORE-008, ADR-027 §5).
 *
 * - A **dedicated private disk**: `attachments` by default (`storage/app/attachments`, `serve => false`,
 *   outside the served `local` root), or the disk named by SN3_ATTACHMENTS_DISK, e.g. a private S3
 *   bucket on Cloud. Nothing is ever written to a public disk, and no URL points at a file: the only
 *   way out is the authorized download route, which re-checks access on every request.
 * - **Opaque, random keys:** `objects/<team_id>/<project_id>/<32 random hex>`. The original file name
 *   is metadata only and never part of a path, so a name cannot traverse or collide. The team and
 *   project prefixes let the trash purge, and the sweeper, delete everything of a purged project or
 *   team by prefix. SN3 only writes, reads and sweeps keys under `objects/`.
 */
final class AttachmentStorage
{
    public const PREFIX = 'objects';

    /** `objects/<team_id>/<project_id>/<random>`, as the storage-key CHECK constraint requires. */
    public const KEY_PATTERN = '#^objects/([0-9a-f-]{36})/([0-9a-f-]{36})/([0-9a-f]{32})$#';

    public function diskName(): string
    {
        return config()->string('sn3.attachments.disk');
    }

    public function disk(?string $name = null): FilesystemAdapter
    {
        return Storage::disk($name ?? $this->diskName());
    }

    /**
     * Every disk attachment files may be on: the configured one, the previous ones the operator
     * listed (`sn3.attachments.previous_disks`), and every disk an attachment row still names (a row
     * keeps the disk it was written to when SN3_ATTACHMENTS_DISK changes). Unknown disk names are
     * skipped. The purge and the sweep clean all of them.
     *
     * @return list<string>
     */
    public function diskNames(): array
    {
        $names = [$this->diskName()];

        foreach (config()->array('sn3.attachments.previous_disks') as $name) {
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        foreach (DB::table('task_attachments')->distinct()->pluck('storage_disk') as $name) {
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        $known = array_keys(config()->array('filesystems.disks'));

        return array_values(array_unique(array_filter($names, fn (string $name): bool => in_array($name, $known, true))));
    }

    /** The disk an attachment's file is on (the one it was written to). */
    public function diskOf(TaskAttachment $attachment): FilesystemAdapter
    {
        return $this->disk($attachment->storage_disk);
    }

    /** A new random key for a file of the project. */
    public function newKey(string $teamId, string $projectId): string
    {
        return $this->projectPrefix($teamId, $projectId).'/'.bin2hex(random_bytes(16));
    }

    public function teamPrefix(string $teamId): string
    {
        return self::PREFIX.'/'.$teamId;
    }

    public function projectPrefix(string $teamId, string $projectId): string
    {
        return $this->teamPrefix($teamId).'/'.$projectId;
    }
}
