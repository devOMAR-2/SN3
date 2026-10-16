<?php

namespace App\Domain\Teams\Actions;

use App\Models\Team;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use App\Support\Exceptions\ResourceLockedException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Moves a team to the 30-day trash (API_SPEC.md §3 `DELETE /teams/{team}`; DECIDED-011, ADR-022 §2).
 * Owner only.
 *
 * Only `teams.deleted_at` is set. The team then disappears from every read path (AccessibleTeams),
 * and with it all of its projects (AccessibleProjects requires a team that is not trashed), and
 * every writer answers 404 because it re-reads the team row without trashed rows. Nothing is
 * deleted and nothing is copied: memberships, projects and their content stay as they are, so
 * POST /restore-deleted brings the team back exactly as it was. The slug stays reserved until the
 * purge (ADR-018 §2). Nothing here, or in the later purge, touches GitHub.
 *
 * An archived team is read-only, deletion included: unarchive it first (423).
 */
final class TrashTeam
{
    public function handle(Team $team, User $actor): void
    {
        DB::transaction(function () use ($team, $actor): void {
            $locked = Team::query()->whereKey($team->id)->lock(Team::WRITE_LOCK)->firstOrFail();

            if ($locked->roleOf($actor) === null) {
                throw (new ModelNotFoundException)->setModel(Team::class, [$team->id]);
            }

            if (! Gate::forUser($actor)->allows('delete', $locked)) {
                throw new DomainConflictException('Team ownership changed; reload and try again.');
            }

            if ($locked->isArchived()) {
                throw new ResourceLockedException('Archived teams are read-only.');
            }

            $locked->delete();

            Log::info('team.trashed', [
                'team_id' => $locked->id,
                'actor_id' => $actor->id,
            ]);
        });
    }
}
