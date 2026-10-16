<?php

namespace App\Domain\Teams\Actions;

use App\Domain\Trash\TrashRetention;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Recovers a team from the trash within 30 days (API_SPEC.md §3 `POST /restore-deleted`;
 * DECIDED-011, ADR-022 §4). Owner only; the `{trashedTeam}` binding (RecoverableResources) already
 * answered 404 to everyone else.
 *
 * Clearing `deleted_at` is all it takes: memberships and projects were never touched, so the team
 * comes back with every project, membership and archived state it had. Projects that were trashed on
 * their own before stay in the trash with their own 30-day clock.
 *
 * Under the team row lock the request re-checks what the binding saw: the team must still exist, be
 * inside the window and be owned by the caller; otherwise 404, never 403 or 409, because a trashed
 * team is invisible to anyone who cannot recover it. A team already restored by a concurrent request
 * is returned unchanged (idempotent).
 */
final class RestoreDeletedTeam
{
    public function handle(Team $team, User $actor): Team
    {
        return DB::transaction(function () use ($team, $actor): Team {
            $locked = Team::withTrashed()->whereKey($team->id)->lock(Team::WRITE_LOCK)->first();

            if ($locked === null || ! Gate::forUser($actor)->allows('restore', $locked)) {
                throw (new ModelNotFoundException)->setModel(Team::class, [$team->id]);
            }

            if (! $locked->trashed()) {
                return $locked;
            }

            if (! TrashRetention::isRecoverable($locked->deleted_at)) {
                throw (new ModelNotFoundException)->setModel(Team::class, [$team->id]);
            }

            $locked->restore();

            Log::info('team.restored_from_trash', [
                'team_id' => $locked->id,
                'actor_id' => $actor->id,
            ]);

            return $locked;
        });
    }
}
