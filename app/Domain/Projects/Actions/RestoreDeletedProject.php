<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\ProjectWriteLock;
use App\Domain\Trash\TrashRetention;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Recovers a project from the trash within 30 days (API_SPEC.md §4 `POST /restore-deleted`;
 * DECIDED-011, acceptance scenario DEL-01, ADR-022 §4). Owner or Project Manager; the
 * `{trashedProject}` binding (RecoverableResources) already answered 404 to everyone else.
 *
 * Clearing `deleted_at` brings the project back with its memberships and content as they were, and
 * increments `version` (DATABASE.md §20), so a representation fetched before the deletion is stale.
 * No `If-Match` is required: no read path exposes a trashed project's version.
 *
 * Its team is locked first, like every project writer. The conservative rules for the parent team:
 * a team in the trash hides its projects, so they cannot be recovered on their own (404: recover the
 * team); an archived team is read-only, so its projects cannot be recovered until it is unarchived
 * (423). Under the locks the request re-checks what the binding saw: the project must still be in
 * the window and recoverable by the caller, otherwise 404. A project already restored by a concurrent
 * request is returned unchanged.
 */
final class RestoreDeletedProject
{
    public function handle(Project $project, User $actor): Project
    {
        return DB::transaction(function () use ($project, $actor): Project {
            $lockedTeam = ProjectWriteLock::team($project->team_id);
            $locked = Project::withTrashed()->whereKey($project->id)->lock(Project::WRITE_LOCK)->first();

            if ($locked === null) {
                throw (new ModelNotFoundException)->setModel(Project::class, [$project->id]);
            }

            $locked->setRelation('team', $lockedTeam);
            $gate = Gate::forUser($actor);

            if (! $gate->allows('view', $locked) || ! $gate->allows('restoreDeleted', $locked)) {
                throw (new ModelNotFoundException)->setModel(Project::class, [$project->id]);
            }

            if (! $locked->trashed()) {
                return $locked;
            }

            if (! TrashRetention::isRecoverable($locked->deleted_at)) {
                throw (new ModelNotFoundException)->setModel(Project::class, [$project->id]);
            }

            $locked->deleted_at = null;
            $locked->version = $locked->version + 1;
            $locked->save();

            Log::info('project.restored_from_trash', [
                'team_id' => $locked->team_id,
                'project_id' => $locked->id,
                'actor_id' => $actor->id,
            ]);

            return $locked;
        });
    }
}
