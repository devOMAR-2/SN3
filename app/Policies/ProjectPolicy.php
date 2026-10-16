<?php

namespace App\Policies;

use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Models\Project;
use App\Models\User;

/**
 * Effective project permissions: the intersection of the team role and the project role, with the
 * Team Owner exception (BRD §7, API_SPEC.md §12 and §17, DATABASE.md §19, DECIDED-003).
 *
 * | Team role | Team-visible project                    | Private project                                  |
 * |-----------|-----------------------------------------|--------------------------------------------------|
 * | Owner     | view, manage, contribute                | view, manage, contribute (no membership needed)  |
 * | Admin     | view, settings, contribute; members as manager | only as a member: manager manages, both contribute |
 * | Member    | view, contribute; manage as manager     | only as a member: manager manages, both contribute |
 * | Viewer    | view                                    | view, only as a member; never writes             |
 *
 * "Manage" is project settings and membership. A Team Admin manages a team-visible project's
 * settings without a membership (API_SPEC.md §17) but its membership only as a Project Manager
 * (API_SPEC.md §4 "Owner or Project Manager"): otherwise an Admin could add themselves to every
 * team-visible project and keep access after one is made private (ADR-020 §5). "Contribute" is
 * task and collaboration writes
 * (CORE-005 onwards). In HTTP flows a user who may not view a project never reaches these checks:
 * the `{project}` binding (AccessibleProjects) answers 404 first, so a 403 here only ever concerns
 * a project the user already knows about. Resource state (archived team or project → 423) belongs
 * to the project actions.
 */
class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        $teamRole = $project->team->roleOf($user);

        if ($teamRole === null) {
            return false;
        }

        return $teamRole->isOwner() || ! $project->isPrivate() || $project->roleOf($user) !== null;
    }

    /**
     * PATCH /projects/{project} (name, key, description, visibility) and PATCH
     * /projects/{project}/settings (API_SPEC.md §5, §17; ADR-021 §6).
     */
    public function update(User $user, Project $project): bool
    {
        return $this->manages($user, $project);
    }

    /** PUT and DELETE /projects/{project}/members/{user}. */
    public function manageMembers(User $user, Project $project): bool
    {
        if (! $this->view($user, $project)) {
            return false;
        }

        return match ($project->team->roleOf($user)) {
            TeamRole::Owner => true,
            TeamRole::Admin, TeamRole::Member => $project->roleOf($user) === ProjectRole::Manager,
            TeamRole::Viewer, null => false,
        };
    }

    /**
     * Workflow status writes and reordering (API_SPEC.md §5 and §18 "Owner/Project Manager";
     * ADR-021 §1). The same rule as membership: a Team Admin needs an explicit `manager` role here
     * even on a team-visible project, and a Team Viewer never qualifies.
     */
    public function manageWorkflow(User $user, Project $project): bool
    {
        return $this->manageMembers($user, $project);
    }

    /** Write access to project content: tasks, comments and ordinary collaboration (CORE-005+). */
    public function contribute(User $user, Project $project): bool
    {
        if (! $this->view($user, $project)) {
            return false;
        }

        return match ($project->team->roleOf($user)) {
            TeamRole::Owner => true,
            TeamRole::Admin, TeamRole::Member => ! $project->isPrivate() || $project->roleOf($user) !== null,
            TeamRole::Viewer, null => false,
        };
    }

    /**
     * POST /projects/{project}/archive and /restore (unarchive). Lifecycle abilities follow API_SPEC.md
     * §4 and DECIDED-011 literally, "Owner or Project Manager": the Team Admin exception for a
     * team-visible project's settings (§17) does not extend to them (ADR-022 §3), and a Team Viewer
     * stays read-only whatever project role they hold. The same rule as project membership.
     */
    public function archive(User $user, Project $project): bool
    {
        return $this->manageMembers($user, $project);
    }

    /** DELETE /projects/{project}: move to the 30-day trash. */
    public function delete(User $user, Project $project): bool
    {
        return $this->manageMembers($user, $project);
    }

    /** POST /projects/{project}/restore-deleted: recover from the trash within 30 days. */
    public function restoreDeleted(User $user, Project $project): bool
    {
        return $this->manageMembers($user, $project);
    }

    private function manages(User $user, Project $project): bool
    {
        if (! $this->view($user, $project)) {
            return false;
        }

        return match ($project->team->roleOf($user)) {
            TeamRole::Owner => true,
            // Owner/Admin manage team-visible projects (API_SPEC.md §17, DATABASE.md §19).
            TeamRole::Admin => ! $project->isPrivate() || $project->roleOf($user) === ProjectRole::Manager,
            TeamRole::Member => $project->roleOf($user) === ProjectRole::Manager,
            // A Team Viewer stays read-only whatever project role they hold.
            TeamRole::Viewer, null => false,
        };
    }
}
