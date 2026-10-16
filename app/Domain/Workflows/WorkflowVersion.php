<?php

namespace App\Domain\Workflows;

use App\Models\Project;
use App\Support\Concurrency\StaleVersionException;
use Illuminate\Support\Facades\DB;

/**
 * The version of a project's workflow as a whole, `projects.workflow_version` (API_SPEC.md §1.4
 * "workflow configuration", ADR-021 §3). Status writers call these on the project row they locked
 * with ProjectWriteLock, after the archived/access checks, so 423 still wins over 412.
 *
 * It is not `projects.version`: workflow edits and project edits do not invalidate each other.
 */
final class WorkflowVersion
{
    /**
     * @throws StaleVersionException
     */
    public static function assertCurrent(Project $lockedProject, int $expectedVersion): void
    {
        if ($lockedProject->workflow_version !== $expectedVersion) {
            throw new StaleVersionException;
        }
    }

    /**
     * Increments the workflow version without touching the project's own version or timestamps.
     */
    public static function bump(Project $lockedProject): void
    {
        DB::update('UPDATE projects SET workflow_version = workflow_version + 1 WHERE id = ?', [$lockedProject->id]);

        $lockedProject->workflow_version++;
        $lockedProject->syncOriginalAttribute('workflow_version');
    }

    /**
     * The `ETag` for a workflow version, matching what `If-Match` expects.
     */
    public static function etag(int $version): string
    {
        return '"'.$version.'"';
    }
}
