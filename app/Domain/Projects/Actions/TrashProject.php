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
 * Moves a project to the 30-day trash (API_SPEC.md §4 `DELETE /projects/{project}`; DECIDED-011,
 * ADR-022 §2). Owner or Project Manager; takes `If-Match` like every project change (§1.4).
 *
 * Only `projects.deleted_at` is set (and `version` incremented). The project then disappears from
 * every read path (AccessibleProjects) and every writer answers 404, because they re-read the
 * project without trashed rows; its memberships and content stay untouched for POST
 * /restore-deleted. The key stays reserved until the purge (ADR-020 §2). Nothing here, or in the
 * later purge, touches GitHub.
 *
 * An archived project, or any project of an archived team, is read-only, deletion included: 423.
 */
final class TrashProject
{
    /**
     * @throws StaleVersionException
     */
    public function handle(Project $project, User $actor, int $expectedVersion): void
    {
        DB::transaction(function () use ($project, $actor, $expectedVersion): void {
            $lockedTeam = ProjectWriteLock::team($project->team_id);

            OptimisticLock::update($project, $expectedVersion, function (Project $locked): void {
                $locked->deleted_at = now();
            }, guard: function (Project $locked) use ($lockedTeam, $actor): void {
                ProjectWriteLock::assertWritable($locked, $lockedTeam, $actor, 'delete');
            }, lock: Project::WRITE_LOCK);

            Log::info('project.trashed', [
                'team_id' => $project->team_id,
                'project_id' => $project->id,
                'actor_id' => $actor->id,
            ]);
        });
    }
}
