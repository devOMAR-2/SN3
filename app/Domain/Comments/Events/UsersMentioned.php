<?php

namespace App\Domain\Comments\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Users were newly mentioned in a comment (CORE-008, ADR-027 §2): the hook CORE-009 notifies from.
 *
 * Dispatched after the comment commits, never for a request that rolls back. It carries IDs only
 * (AGENTS.md §5 "Async"): a listener must re-resolve the comment, its task and project, and re-check
 * that each user can still see the project at send time (API_SPEC.md §9, FR-COL-005), because access
 * may change between the comment and the notification. Nothing listens yet.
 */
final class UsersMentioned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<string>  $userIds  users who were not mentioned in this comment before
     */
    public function __construct(
        public readonly string $commentId,
        public readonly string $taskId,
        public readonly string $projectId,
        public readonly string $teamId,
        public readonly string $actorId,
        public readonly array $userIds,
    ) {}
}
