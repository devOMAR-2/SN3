<?php

namespace App\Support\Tenancy;

use App\Domain\Projects\ProjectVisibility;
use App\Domain\Teams\TeamRole;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * The single authorization query for projects (DATABASE.md §10.2, ARCHITECTURE.md §5.2 steps 1–4,
 * DECIDED-003). A user sees a project when they belong to its (not trashed) team and either
 *
 * - the project is team-visible, or
 * - they hold an explicit project membership, or
 * - they are the Team Owner.
 *
 * Nothing else grants visibility: a Team Admin without a membership row gets no private project,
 * no count and no 403 that would confirm one exists. Lists, detail routes, counts and every later
 * project-scoped resource (tasks, search, attachments, notifications, exports, GitHub metadata)
 * must go through this class: either the `{project}` route binding (TenancyServiceProvider) or
 * `constrain()` on their own project relation, e.g.
 * `Task::query()->whereHas('project', fn ($p) => $accessibleProjects->constrain($p, $user))`.
 *
 * A hidden, trashed, foreign, random or malformed project ID all behave exactly like a missing one.
 */
final class AccessibleProjects
{
    /**
     * Every project the user can see, across all their teams, with the team (and the user's own
     * team membership) and the user's own project membership eager-loaded for policy checks.
     *
     * @return Builder<Project>
     */
    public function query(User $user): Builder
    {
        return $this->constrain(Project::query(), $user)->with([
            'team.memberships' =>
                /** @param HasMany<TeamMembership, Team> $memberships */
                static function (HasMany $memberships) use ($user): void {
                    $memberships->where('user_id', $user->id);
                },
            'memberships' =>
                /** @param HasMany<ProjectMembership, Project> $memberships */
                static function (HasMany $memberships) use ($user): void {
                    $memberships->where('user_id', $user->id);
                },
        ])->afterQuery(
            // Runs on every model-returning path (get, cursorPaginate, first…). pluck() and other
            // scalar results also pass through here, so only Project models are marked.
            static function (mixed $results) use ($user): void {
                if (! $results instanceof Collection) {
                    return;
                }

                foreach ($results as $project) {
                    if ($project instanceof Project) {
                        $project->membershipsLoadedFor = $user->id;
                    }
                }
            },
        );
    }

    /**
     * The projects of one team that the user can see.
     *
     * @return Builder<Project>
     */
    public function inTeam(Team $team, User $user): Builder
    {
        return $this->query($user)->where('projects.team_id', $team->id);
    }

    /**
     * Restricts any project query to the projects the user can see.
     *
     * @param  Builder<Project>  $projects
     * @return Builder<Project>
     */
    public function constrain(Builder $projects, User $user): Builder
    {
        return $projects
            ->whereHas('team', function (Builder $team) use ($user): void {
                $team->whereRelation('memberships', 'user_id', $user->id);
            })
            ->where(function (Builder $visible) use ($user): void {
                $visible
                    ->where('projects.visibility', ProjectVisibility::Team->value)
                    ->orWhereHas('memberships', function (Builder $membership) use ($user): void {
                        $membership->where('user_id', $user->id);
                    })
                    ->orWhereHas('team.memberships', function (Builder $membership) use ($user): void {
                        $membership->where('user_id', $user->id)->where('role', TeamRole::Owner->value);
                    });
            });
    }

    /**
     * @throws ModelNotFoundException<Project>
     */
    public function findOrFail(User $user, string $id): Project
    {
        // Comparing a non-UUID with a uuid column is a PostgreSQL error, not a miss.
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException)->setModel(Project::class, [$id]);
        }

        return $this->query($user)->whereKey($id)->firstOrFail();
    }
}
