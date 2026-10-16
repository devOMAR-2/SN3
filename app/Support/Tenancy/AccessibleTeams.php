<?php

namespace App\Support\Tenancy;

use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * The single way to reach a team on behalf of a user (ARCHITECTURE.md §5.2 steps 1–3,
 * DATABASE.md §10). Team-owned resources are then resolved through the returned team's
 * relations, never by a bare `find($id)`.
 *
 * A team the user does not belong to, a soft-deleted team and a malformed ID all behave exactly
 * like a missing one (404), so guessing IDs reveals nothing.
 */
final class AccessibleTeams
{
    /**
     * Teams the user is a member of, with the user's own membership eager-loaded.
     *
     * @return Builder<Team>
     */
    public function query(User $user): Builder
    {
        return Team::query()
            ->whereRelation('memberships', 'user_id', $user->id)
            ->with([
                'memberships' =>
                    /** @param HasMany<TeamMembership, Team> $memberships */
                    static function (HasMany $memberships) use ($user): void {
                        $memberships->where('user_id', $user->id);
                    },
            ]);
    }

    /**
     * @throws ModelNotFoundException<Team>
     */
    public function findOrFail(User $user, string $id): Team
    {
        // Comparing a non-UUID with a uuid column is a PostgreSQL error, not a miss.
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException)->setModel(Team::class, [$id]);
        }

        return $this->query($user)->whereKey($id)->firstOrFail();
    }
}
