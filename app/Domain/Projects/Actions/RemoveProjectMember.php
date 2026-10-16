<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\ProjectWriteLock;
use App\Domain\Tasks\TaskAssignments;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Removes an explicit project membership (FR-PROJ-006, API_SPEC.md §4). Owner or project manager
 * only. Access to a private project ends with the row, because every read re-evaluates
 * AccessibleProjects (DATABASE.md §16 DB-T08). The Team Owner keeps implicit access regardless.
 */
final class RemoveProjectMember
{
    /**
     * @throws ModelNotFoundException<ProjectMembership>
     */
    public function handle(Project $project, User $actor, string $userId): void
    {
        DB::transaction(function () use ($project, $actor, $userId): void {
            $locked = ProjectWriteLock::project($project, $actor, 'manageMembers');

            $membership = ProjectMembership::query()
                ->where('project_id', $locked->id)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();

            $membership->delete();

            // Without the membership they may no longer write in a private project (ADR-023 §5).
            TaskAssignments::releaseIneligible($locked->team_id, $userId, $locked->id, 'project_member_removed');

            Log::info('project.member_removed', [
                'team_id' => $locked->team_id,
                'project_id' => $locked->id,
                'user_id' => $userId,
                'actor_id' => $actor->id,
                'role' => $membership->project_role->value,
            ]);
        });
    }
}
