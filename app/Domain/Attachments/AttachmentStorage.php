<?php

namespace App\Domain\Attachments;

use App\Models\TaskAttachment;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use stdClass;

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

    /**
     * diskNames(), once per instance: a sweep or purge run resolves its own instance.
     *
     * @var list<string>|null
     */
    private ?array $diskNames = null;

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
     * skipped. The purge and the sweep clean all of them. Two names may point at one root or bucket:
     * callers must never treat "not a row of this disk name" as "not live" (SweepAttachments).
     *
     * @return list<string>
     */
    public function diskNames(): array
    {
        if ($this->diskNames !== null) {
            return $this->diskNames;
        }

        $names = [$this->diskName()];

        foreach (config()->array('sn3.attachments.previous_disks') as $name) {
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        // The distinct disks as a loose index scan over task_attachments_storage_disk_index: one probe
        // per disk, not a pass over every row.
        $rows = DB::select(
            'WITH RECURSIVE d AS ('
            .'SELECT min(storage_disk) AS name FROM task_attachments '
            .'UNION ALL SELECT (SELECT min(storage_disk) FROM task_attachments WHERE storage_disk > d.name) FROM d WHERE d.name IS NOT NULL'
            .') SELECT name FROM d WHERE name IS NOT NULL',
        );

        foreach ($rows as $row) {
            if ($row instanceof stdClass && is_string($row->name ?? null)) {
                $names[] = $row->name;
            }
        }

        $known = array_keys(config()->array('filesystems.disks'));

        return $this->diskNames = array_values(array_unique(array_filter($names, fn (string $name): bool => in_array($name, $known, true))));
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
