<?php

namespace App\Domain\Teams\Actions;

use App\Models\Team;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Archives or unarchives a team (API_SPEC.md §3 `POST /archive`, `POST /restore`; DECIDED-011,
 * ADR-022 §2). Owner only.
 *
 * An archived team is read-only, and so is everything in it: every team and project writer locks
 * the team row and answers 423 while `archived_at` is set. The projects' own `archived_at` is left
 * alone, so unarchiving the team returns each project to the state it had (a project archived on
 * its own stays archived). Reads keep working throughout.
 *
 * Both directions are idempotent: archiving an archived team, or unarchiving an active one, changes
 * nothing and answers 200, so a retried request is harmless.
 *
 * The team row is locked first, like every team writer (ADR-018 §4), and the caller's ownership is
 * re-checked on it: a team the caller no longer belongs to (or that was trashed meanwhile) is a 404,
 * a lost ownership a 409.
 */
final class ArchiveTeam
{
    public function handle(Team $team, User $actor, bool $archive): Team
    {
        return DB::transaction(function () use ($team, $actor, $archive): Team {
            $locked = Team::query()->whereKey($team->id)->lock(Team::WRITE_LOCK)->firstOrFail();

            if ($locked->roleOf($actor) === null) {
                throw (new ModelNotFoundException)->setModel(Team::class, [$team->id]);
            }

            if (! Gate::forUser($actor)->allows('archive', $locked)) {
                throw new DomainConflictException('Team ownership changed; reload and try again.');
            }

            if ($locked->isArchived() === $archive) {
                return $locked;
            }

            $locked->archived_at = $archive ? now() : null;
            $locked->save();

            Log::info($archive ? 'team.archived' : 'team.unarchived', [
                'team_id' => $locked->id,
                'actor_id' => $actor->id,
            ]);

            return $locked;
        });
    }
}
