<?php

namespace App\Domain\Invitations\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An email invitation was created (CORE-009, ADR-029 §2; ADR-025 "Consequences"): the hook the in-app
 * invitation notification is sent from. Share links have no recipient and send nothing.
 *
 * Dispatched after the invitation commits; IDs only. The listener re-reads the invitation and looks up
 * the account behind the address itself, so neither the address nor the token travels on the queue.
 */
final class InvitationSent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $invitationId,
        public readonly string $teamId,
    ) {}
}
