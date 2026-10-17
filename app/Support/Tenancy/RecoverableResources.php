<?php

namespace App\Support\Tenancy;

use App\Domain\Teams\TeamRole;
use App\Domain\Trash\TrashRetention;
use App\Models\Project;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Resolves a team or project **in the trash** for the one endpoint that needs it,
 * `POST …/restore-deleted` (CORE-010, ADR-022 §4). AccessibleTeams and AccessibleProjects keep
 * hiding trashed rows from every other path; this class does not relax them.
 *
 * It returns a trashed row only to a caller who may recover it, and only within the 30-day window:
 *
 * - a team: only its Owner (TeamPolicy::restore);
 * - a project: only someone who could see it before it was trashed (the AccessibleProjects
 *   predicate, so a private project stays hidden from non-members, Team Admins included) **and**
 *   may recover it (ProjectPolicy::restoreDeleted: the Owner or a Project Manager), in a team that
 *   is not itself in the trash.
 *
 * Everyone else, a live (not trashed) row, an expired one, a foreign, random or malformed ID: the
 * same 404 as a missing resource. Even former colleagues learn nothing from the trash, so there is
 * no 403 here.
 */
final class RecoverableResources
{
    public function __construct(private readonly AccessibleProjects $projects) {}

    /**
     * @throws ModelNotFoundException<Team>
     */
    public function team(User $user, string $id): Team
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException)->setModel(Team::class, [$id]);
        }

        // Trashed rows only: `deleted_at > cutoff` is never true for a live row (NULL), so it is the
        // trash filter and the 30-day window at once.
        $team = Team::query()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->whereKey($id)
            ->where('teams.deleted_at', '>', TrashRetention::cutoff())
            ->whereHas('memberships', function (Builder $membership) use ($user): void {
                $membership->where('user_id', $user->id)->where('role', TeamRole::Owner->value);
            })
            ->with([
                'memberships' =>
                    /** @param HasMany<TeamMembership, Team> $memberships */
                    static function (HasMany $memberships) use ($user): void {
                        $memberships->where('user_id', $user->id);
                    },
            ])
            ->first();

        if ($team === null || ! Gate::forUser($user)->allows('restore', $team)) {
            throw (new ModelNotFoundException)->setModel(Team::class, [$id]);
        }

        return $team;
    }

    /**
     * The trashed teams the user may still recover (their Owner, within 30 days), most recently
     * deleted first, for the "Deleted teams" list (CORE-006, ADR-026 §6). The same predicate as
     * team(); at most `$limit` rows.
     *
     * @return list<Team>
     */
    public function teams(User $user, int $limit = 100): array
    {
        $teams = Team::query()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->where('teams.deleted_at', '>', TrashRetention::cutoff())
            ->whereHas('memberships', function (Builder $membership) use ($user): void {
                $membership->where('user_id', $user->id)->where('role', TeamRole::Owner->value);
            })
            ->with([
                'memberships' =>
                    /** @param HasMany<TeamMembership, Team> $memberships */
                    static function (HasMany $memberships) use ($user): void {
                        $memberships->where('user_id', $user->id);
                    },
            ])
            ->orderByDesc('teams.deleted_at')
            ->orderByDesc('teams.id')
            ->limit($limit)
            ->get();

        return array_values($teams->filter(fn (Team $team): bool => Gate::forUser($user)->allows('restore', $team))->all());
    }

    /**
     * The trashed projects of a live team that the user may still recover (the AccessibleProjects
     * predicate on trashed rows, ProjectPolicy::restoreDeleted, within 30 days), most recently deleted
     * first (CORE-006, ADR-026 §6). The same rule as project(); at most `$limit` rows.
     *
     * @return list<Project>
     */
    public function projectsInTeam(Team $team, User $user, int $limit = 100): array
    {
        $projects = $this->projects->inTeam($team, $user)
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->where('projects.deleted_at', '>', TrashRetention::cutoff())
            ->orderByDesc('projects.deleted_at')
            ->orderByDesc('projects.id')
            ->limit($limit)
            ->get();

        return array_values($projects->filter(fn (Project $project): bool => Gate::forUser($user)->allows('restoreDeleted', $project))->all());
    }

    /**
     * @throws ModelNotFoundException<Project>
     */
    public function project(User $user, string $id): Project
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException)->setModel(Project::class, [$id]);
        }

        // The same visibility predicate and eager loads as every other project read, on trashed rows
        // only: `deleted_at > cutoff` is never true for a live row (NULL), so it is the trash filter
        // and the 30-day window at once.
        $project = $this->projects->query($user)
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->whereKey($id)
            ->where('projects.deleted_at', '>', TrashRetention::cutoff())
            ->first();

        if ($project === null || ! Gate::forUser($user)->allows('restoreDeleted', $project)) {
            throw (new ModelNotFoundException)->setModel(Project::class, [$id]);
        }

        return $project;
    }
}
