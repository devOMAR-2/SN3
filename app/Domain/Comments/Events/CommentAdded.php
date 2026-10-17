<?php

namespace App\Domain\Comments\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A comment was added to a task (CORE-009, ADR-029 §2): the hook the watchers' comment notifications
 * are sent from. Mentions have their own event (UsersMentioned).
 *
 * Dispatched after the comment commits; IDs only. The listener re-resolves the comment, its task and
 * project, and re-checks who may see them when it runs.
 */
final class CommentAdded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $commentId,
        public readonly string $taskId,
        public readonly string $projectId,
        public readonly string $teamId,
        public readonly string $actorId,
    ) {}
}
