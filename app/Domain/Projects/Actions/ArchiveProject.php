<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\ProjectWriteLock;
use App\Models\Project;
use App\Models\User;
use App\Support\Concurrency\OptimisticLock;
use App\Support\Concurrency\StaleVersionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Archives or unarchives a project (API_SPEC.md §4 `POST /archive`, `POST /restore`; FR-PROJ-008,
 * DECIDED-011, ADR-022 §2). Owner or Project Manager.
 *
 * An archived project is read-only (every project writer answers 423) and stays fully readable; its
 * history is untouched. Archiving changes the Project resource, so it takes `If-Match` and increments
 * `version` like any other project change (API_SPEC.md §1.4, DATABASE.md §20). Both directions are
 * idempotent: when the project is already in the requested state nothing changes and the version is
 * kept.
 *
 * Locks the team row first (an archived team is read-only, so its projects cannot be archived or
 * unarchived either: 423), then the project row, and re-checks the caller's access on the locked
 * rows before the version comparison (404 if they can no longer see it, 409 if they lost the right).
 */
final class ArchiveProject
{
    /**
     * @throws StaleVersionException
     */
    public function handle(Project $project, User $actor, int $expectedVersion, bool $archive): Project
    {
        return DB::transaction(function () use ($project, $actor, $expectedVersion, $archive): Project {
            $lockedTeam = ProjectWriteLock::team($project->team_id);

            $updated = OptimisticLock::update($project, $expectedVersion, function (Project $locked) use ($archive): void {
                if ($locked->isArchived() !== $archive) {
                    $locked->archived_at = $archive ? now() : null;
                }
            }, guard: function (Project $locked) use ($lockedTeam, $actor): void {
                ProjectWriteLock::assertAllowed($locked, $lockedTeam, $actor, 'archive');
            }, lock: Project::WRITE_LOCK);

            if ($updated->wasChanged('archived_at')) {
                Log::info($archive ? 'project.archived' : 'project.unarchived', [
                    'team_id' => $updated->team_id,
                    'project_id' => $updated->id,
                    'actor_id' => $actor->id,
                ]);
            }

            return $updated;
        });
    }
}
