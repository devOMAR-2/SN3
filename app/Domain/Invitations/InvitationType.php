<?php

namespace App\Domain\Invitations;

/**
 * DECIDED-007: an invitation is bound to one email address, or a reusable share link.
 */
enum InvitationType: string
{
    case Email = 'email';
    case Link = 'link';
}
