<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Activity\Activity;
use App\Domain\Projects\ProjectRole;
use App\Domain\Projects\ProjectWriteLock;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Adds a team member to a project or changes their project role (FR-PROJ-006, API_SPEC.md §4
 * `PUT /projects/{project}/members/{user}`). Only an Owner or project manager may do this; for a
 * private project nobody else even sees it, so a Team Admin outside it cannot add themselves.
 *
 * Only existing members of the project's own team are eligible (else 404, the same answer as for
 * an unknown user). The target's team membership is read after the team lock, so a concurrent
 * removal from the team has either committed (404) or waits for this write.
 *
 * `$membership->wasRecentlyCreated` tells the caller whether this added (201) or updated (200).
 */
final class SetProjectMember
{
    /**
     * @throws ModelNotFoundException<TeamMembership>
     */
    public function handle(Project $project, User $actor, string $userId, ProjectRole $role): ProjectMembership
    {
        return DB::transaction(function () use ($project, $actor, $userId, $role): ProjectMembership {
            $locked = ProjectWriteLock::project($project, $actor, 'manageMembers');

            $teamMembership = TeamMembership::query()
                ->where('team_id', $locked->team_id)
                ->where('user_id', $userId)
                ->with('user')
                ->firstOrFail();

            $membership = ProjectMembership::query()
                ->where('project_id', $locked->id)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                $membership = new ProjectMembership;
                $membership->forceFill([
                    'project_id' => $locked->id,
                    'team_id' => $locked->team_id,
                    'user_id' => $userId,
                    'project_role' => $role,
                    'added_by' => $actor->id,
                ])->save();

                Activity::project($locked, $actor, 'project.member_added', ['user_id' => $userId, 'role' => $role->value]);
            } elseif ($membership->project_role !== $role) {
                $from = $membership->project_role;
                $membership->project_role = $role;
                $membership->save();

                Activity::project($locked, $actor, 'project.member_role_changed', ['user_id' => $userId, 'from' => $from->value, 'to' => $role->value]);
            }

            return $membership->setRelation('user', $teamMembership->user);
        });
    }
}
