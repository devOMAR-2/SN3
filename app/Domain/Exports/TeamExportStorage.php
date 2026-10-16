<?php

namespace App\Domain\Exports;

use App\Models\TeamExport;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * Where export archives live (ADR-024 §4): the private `exports` disk by default
 * (`storage/app/exports`, never served directly), or the disk named by SN3_EXPORTS_DISK,
 * e.g. a private S3 bucket on Cloud. Files are only ever handed out by the signed, Owner-only
 * download route.
 */
final class TeamExportStorage
{
    /** `<team_id>/<export_id>.zip`; the pattern `exports:prune` uses to find orphaned files. */
    public const KEY_PATTERN = '#^([0-9a-f-]{36})/([0-9a-f-]{36})\.zip$#';

    public function disk(): FilesystemAdapter
    {
        return Storage::disk(config()->string('sn3.exports.disk'));
    }

    public function key(TeamExport $export): string
    {
        return $export->team_id.'/'.$export->id.'.zip';
    }
}
