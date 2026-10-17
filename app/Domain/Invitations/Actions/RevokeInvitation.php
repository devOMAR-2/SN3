<?php

namespace App\Domain\Invitations\Actions;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use App\Support\Exceptions\ResourceLockedException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Revokes an invitation (API_SPEC.md §3.1 `DELETE /teams/{team}/invitations/{invitation}`; ADR-025
 * §3). Owner or Admin, any invitation of the team. Idempotent: revoking a revoked, used-up or
 * expired invitation changes nothing and still succeeds.
 *
 * Locks the team row, then the invitation; re-checks on the locked team: archived 423, a team the
 * actor left meanwhile 404, a right lost meanwhile 409.
 */
final class RevokeInvitation
{
    public function handle(Team $team, User $actor, TeamInvitation $invitation): void
    {
        DB::transaction(function () use ($team, $actor, $invitation): void {
            $locked = Team::query()->whereKey($team->id)->lock(Team::WRITE_LOCK)->firstOrFail();

            if ($locked->roleOf($actor) === null) {
                throw (new ModelNotFoundException)->setModel(Team::class, [$team->id]);
            }

            if ($locked->isArchived()) {
                throw new ResourceLockedException('Archived teams are read-only.');
            }

            if (! Gate::forUser($actor)->allows('invite', $locked)) {
                throw new DomainConflictException('Your role in this team changed; reload and try again.');
            }

            $row = TeamInvitation::query()
                ->select(TeamInvitation::COLUMNS)
                ->whereKey($invitation->id)
                ->where('team_id', $locked->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($row->revoked_at !== null || $row->accepted_at !== null) {
                return;
            }

            $row->revoked_at = now();
            $row->save();

            Log::info('team.invitation_revoked', [
                'team_id' => $locked->id,
                'invitation_id' => $row->id,
                'actor_id' => $actor->id,
            ]);
        });
    }
}
