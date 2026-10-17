<?php

namespace App\Domain\Tasks\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A task was created with an assignee or really changed (CORE-009, ADR-029 §2): the hook the assignment
 * and watcher notifications are sent from.
 *
 * Dispatched after the change commits, never for a refused, stale or no-op write. It carries IDs and
 * field names only (AGENTS.md §5 "Async"): the listener re-resolves the task, its project and team and
 * re-checks who may see it when it runs.
 */
final class TaskChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<string>  $fields  the changed fields (`task.updated` activity names); empty for a new task
     * @param  string|null  $assignedUserId  the new assignee, when this change assigned someone
     */
    public function __construct(
        public readonly string $taskId,
        public readonly string $projectId,
        public readonly string $teamId,
        public readonly string $actorId,
        public readonly array $fields,
        public readonly ?string $assignedUserId,
    ) {}
}
