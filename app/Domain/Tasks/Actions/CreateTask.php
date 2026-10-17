<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Activity\Activity;
use App\Domain\Projects\ProjectWriteLock;
use App\Domain\Projects\TaskNumbers;
use App\Domain\Tasks\Events\TaskChanged;
use App\Domain\Tasks\TaskAssignments;
use App\Domain\Tasks\TaskHierarchy;
use App\Domain\Tasks\TaskLabels;
use App\Domain\Tasks\TaskOrder;
use App\Domain\Tasks\TaskPriority;
use App\Domain\Workflows\StatusCategory;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Creates a task (API_SPEC.md §6.1 POST, DATABASE.md §11 "Task creation", FR-TASK-002/003/004).
 *
 * One transaction (the `idempotent` middleware's, when the route has it):
 *
 * 1. ProjectWriteLock::project(…, 'contribute'): team row, then project row; 423 when either is
 *    archived, 404 when the actor can no longer see the project, 409 when they lost the right.
 * 2. References are checked on the locked state, each answered like a random ID when it is not
 *    usable (422 `exists`): the status must belong to the project (DB-T04), the assignee must be
 *    eligible (TaskAssignments, DB-T03), the labels and the parent must belong to the project, and
 *    the parent must leave room for one more level (TaskHierarchy, TASK-01).
 * 3. TaskNumbers::allocate() issues the next number in the same transaction (DB-T05), which also
 *    freezes the project key (PROJ-01).
 * 4. The task is inserted at the end of its status column; new tasks start in the default status
 *    (ADR-021 §4) unless `status_id` names another status of the project.
 */
final class CreateTask
{
    /**
     * @param  array{title: string, description?: string|null, status_id?: string|null, priority?: string, assignee_user_id?: string|null, due_date?: string|null, label_ids?: list<string>, parent_task_id?: string|null}  $input
     */
    public function handle(Project $project, User $actor, array $input): Task
    {
        return DB::transaction(function () use ($project, $actor, $input): Task {
            $locked = ProjectWriteLock::project($project, $actor, 'contribute');

            $status = self::status($locked, $input['status_id'] ?? null);
            $assigneeId = $input['assignee_user_id'] ?? null;

            if ($assigneeId !== null && ! TaskAssignments::eligible($locked->id, $assigneeId)) {
                throw ValidationException::withMessages(['assignee_user_id' => 'exists']);
            }

            $labelIds = $input['label_ids'] ?? [];
            TaskLabels::assertInProject($locked, $labelIds);

            $parentId = $input['parent_task_id'] ?? null;

            if ($parentId !== null) {
                TaskHierarchy::assertParent($locked, null, $parentId);
            }

            $task = new Task;
            $task->forceFill([
                'team_id' => $locked->team_id,
                'project_id' => $locked->id,
                'task_number' => TaskNumbers::allocate($locked),
                'title' => $input['title'],
                'description' => $input['description'] ?? null,
                'status_id' => $status->id,
                'assignee_user_id' => $assigneeId,
                'created_by' => $actor->id,
                'priority' => TaskPriority::from($input['priority'] ?? TaskPriority::None->value),
                'due_date' => $input['due_date'] ?? null,
                'position' => TaskOrder::next($locked->id, $status->id),
                'parent_task_id' => $parentId,
            ])->save();

            TaskLabels::sync($task, $labelIds);

            // In the same transaction as the task (DATABASE.md §11 "insert task and activity atomically").
            Activity::task($task, $actor, 'task.created', array_filter([
                'status_id' => $status->id,
                'assignee_user_id' => $assigneeId,
                'parent_task_id' => $parentId,
                'priority' => $task->priority->value,
                'due_date' => $input['due_date'] ?? null,
            ], fn (mixed $value): bool => $value !== null));

            // The new assignee's notification (CORE-009), after commit.
            if ($assigneeId !== null && $assigneeId !== $actor->id) {
                TaskChanged::dispatch($task->id, $locked->id, $locked->team_id, $actor->id, [], $assigneeId);
            }

            return $task->setRelation('project', $locked);
        });
    }

    /**
     * The requested status of this project, or the project's default status.
     */
    private static function status(Project $locked, ?string $statusId): ProjectStatus
    {
        if ($statusId !== null) {
            return $locked->statuses()->whereKey($statusId)->first()
                ?? throw ValidationException::withMessages(['status_id' => 'exists']);
        }

        // Every project has a default `todo` status (ADR-021 §4); the fallback only guards rows that
        // somehow lost the flag.
        return $locked->statuses()->where('is_default', true)->first()
            ?? $locked->statuses()->where('category', StatusCategory::Todo->value)->orderBy('position')->first()
            ?? throw new LogicException('The project has no status to start a task in.');
    }
}
