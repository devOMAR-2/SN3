<?php

namespace App\Domain\Exports\Sections;

use App\Domain\Exports\TeamExportContext;
use App\Domain\Exports\TeamExportSection;
use App\Models\TeamMembership;

/**
 * `data/members.json`: team memberships with each member's name and email, which the Owner already
 * sees in `GET /teams/{team}/members`. Nothing else from the user row: no password hash, tokens,
 * preferences or other teams.
 */
final class MembersSection implements TeamExportSection
{
    public function name(): string
    {
        return 'members';
    }

    public function records(TeamExportContext $context): iterable
    {
        $memberships = TeamMembership::query()
            ->where('team_id', $context->team->id)
            ->with('user:id,name,email')
            ->lazyById(500);

        foreach ($memberships as $membership) {
            yield [
                'user_id' => $membership->user_id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'role' => $membership->role->value,
                'joined_at' => TeamExportContext::timestamp($membership->joined_at),
            ];
        }
    }
}
