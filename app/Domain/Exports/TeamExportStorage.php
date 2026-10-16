<?php

namespace App\Domain\Exports;

use App\Models\TeamExport;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * Where export archives live (ADR-024 §4).
 *
 * - **Archives** go on a dedicated private disk: `exports` by default (`storage/app/exports`, never
 *   served directly), or the disk named by SN3_EXPORTS_DISK, e.g. a private S3 bucket on Cloud.
 *   SN3 only ever writes, reads and sweeps keys under the fixed `archives/` prefix, so even a disk
 *   shared by mistake loses nothing else to `exports:prune`. Files are handed out only by the
 *   signed, Owner-only download route.
 * - **Work directories** are local (PharData needs a real file): one deterministic directory per
 *   export under `sn3.exports.work_dir` (`storage/app/exports/work` by default, outside the archive
 *   prefix and outside the backups). A job clears its own directory when it starts, and
 *   `exports:prune` removes any left older than WORK_TTL_MINUTES, so plaintext from a worker killed
 *   mid-build (timeout, OOM) never outlives the export retention.
 */
final class TeamExportStorage
{
    public const PREFIX = 'archives';

    /** `archives/<team_id>/<export_id>.zip`; the pattern `exports:prune` uses to find orphaned files. */
    public const KEY_PATTERN = '#^archives/([0-9a-f-]{36})/([0-9a-f-]{36})\.zip$#';

    /** Well above a job's worst case (3 attempts of 60 s plus backoff), well below 24 hours. */
    public const WORK_TTL_MINUTES = 120;

    public function disk(): FilesystemAdapter
    {
        return Storage::disk(config()->string('sn3.exports.disk'));
    }

    public function key(TeamExport $export): string
    {
        return self::PREFIX.'/'.$export->team_id.'/'.$export->id.'.zip';
    }

    public function workRoot(): string
    {
        return rtrim(config()->string('sn3.exports.work_dir'), DIRECTORY_SEPARATOR);
    }

    public function workDir(TeamExport $export): string
    {
        return $this->workRoot().DIRECTORY_SEPARATOR.$export->id;
    }
}
