<?php

namespace App\Domain\Projects;

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use App\Support\Exceptions\ResourceLockedException;
use Illuminate\Support\Facades\Gate;

/**
 * Lock order and state checks shared by every project writer (ADR-018 §4, ADR-020 §5). Call inside
 * the writer's transaction.
 *
 * 1. Lock the team row (`Team::WRITE_LOCK`) and refuse an archived team (423). Team writers take
 *    the same lock first, so a project write cannot interleave with a member removal, a role change
 *    or an ownership transfer, and lock order stays team → project → memberships (no deadlocks).
 * 2. Lock the project row and refuse an archived project (423).
 * 3. Re-check the actor's ability on the locked state: their team or project role may have changed
 *    while the request waited for the lock (409, reload and retry).
 */
final class ProjectWriteLock
{
    public static function team(string $teamId): Team
    {
        $team = Team::query()->whereKey($teamId)->lock(Team::WRITE_LOCK)->firstOrFail();

        if ($team->isArchived()) {
            throw new ResourceLockedException('Archived teams are read-only.');
        }

        return $team;
    }

    /**
     * Locks the team and then the project; a project trashed meanwhile is a 404.
     */
    public static function project(Project $project, User $actor, string $ability): Project
    {
        $team = self::team($project->team_id);
        $locked = Project::query()->whereKey($project->id)->lock(Project::WRITE_LOCK)->firstOrFail();

        self::assertWritable($locked, $team, $actor, $ability);

        return $locked;
    }

    /**
     * For a project row the caller has locked itself, after self::team().
     */
    public static function assertWritable(Project $locked, Team $lockedTeam, User $actor, string $ability): void
    {
        if ($locked->isArchived()) {
            throw new ResourceLockedException('Archived projects are read-only.');
        }

        $locked->setRelation('team', $lockedTeam);

        if (! Gate::forUser($actor)->allows($ability, $locked)) {
            throw new DomainConflictException('Project access changed while the request waited; reload and try again.');
        }
    }
}
