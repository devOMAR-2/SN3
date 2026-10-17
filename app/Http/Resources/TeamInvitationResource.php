<?php

namespace App\Http\Resources;

use App\Domain\Invitations\InvitationType;
use App\Models\TeamInvitation;

/**
 * Invitation metadata for the team's Owner and Admins (API_SPEC.md §3.1; ADR-025 §3). Never the
 * token or its hash: the raw token appears once, in the create response only.
 */
final class TeamInvitationResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(TeamInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'team_id' => $invitation->team_id,
            'type' => ($invitation->isEmailBound() ? InvitationType::Email : InvitationType::Link)->value,
            'email' => $invitation->email,
            'role' => $invitation->invited_role->value,
            'status' => $invitation->status()->value,
            'use_count' => $invitation->use_count,
            'max_uses' => $invitation->max_uses,
            'invited_by' => [
                'user_id' => $invitation->invited_by,
                'name' => $invitation->inviter->name,
            ],
            'expires_at' => $invitation->expires_at->utc()->toIso8601ZuluString(),
            'accepted_at' => $invitation->accepted_at?->utc()->toIso8601ZuluString(),
            'revoked_at' => $invitation->revoked_at?->utc()->toIso8601ZuluString(),
            'created_at' => $invitation->created_at?->utc()->toIso8601ZuluString(),
        ];
    }

    /**
     * The minimal preview a token holder sees, signed in or not (API_SPEC.md §3.1): team name and
     * role, plus the kind of invitation and its expiry. Nothing about the team's members or projects.
     *
     * @return array<string, mixed>
     */
    public static function preview(TeamInvitation $invitation, string $teamName): array
    {
        return [
            'team' => ['name' => $teamName],
            'role' => $invitation->invited_role->value,
            'type' => ($invitation->isEmailBound() ? InvitationType::Email : InvitationType::Link)->value,
            'expires_at' => $invitation->expires_at->utc()->toIso8601ZuluString(),
        ];
    }
}
