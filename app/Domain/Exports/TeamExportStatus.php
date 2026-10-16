<?php

namespace App\Domain\Exports;

/**
 * Stored state of a team export (DATABASE.md §20 `team_exports.status`, ADR-024 §3).
 *
 * `pending` → `processing` → `ready` or `failed`. The API additionally reports `expired` for a
 * `ready` export past its `expires_at`, until `exports:prune` deletes it (TeamExportResource).
 */
enum TeamExportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    /** Pending or processing: at most one per team. */
    public function inFlight(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }
}
