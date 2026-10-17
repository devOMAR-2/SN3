<?php

namespace App\Domain\Invitations;

/**
 * The state of a team invitation as the list shows it (API_SPEC.md §3.1, ADR-025 §3). Derived from
 * the row, never stored: `revoked` wins over `accepted` (all uses taken), which wins over `expired`.
 */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
