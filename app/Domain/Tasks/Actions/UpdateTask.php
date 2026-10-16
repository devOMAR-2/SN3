<?php

namespace App\Domain\Tasks\Actions;

use App\Domain\Projects\ProjectWriteLock;
use App\Domain\Tasks\TaskAssignments;
use App\Domain\Tasks\TaskHierarchy;
use App\Domain\Tasks\TaskLabels;
use App\Domain\Tasks\TaskOrder;
use App\Domain\Tasks\TaskPriority;
use App\Models\Task;
use App\Models\User;
use App\Support\Concurrency\OptimisticLock;
use App\Support\Concurrency\StaleVersionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Edits a task (API_SPEC.md §6.1 PATCH, FR-TASK-004) against its `If-Match` version (§1.4, §14.3).
 *
 * Locks team → project (ProjectWriteLock, `contribute`) → task. 423, 404 and 409 from the lock come
 * before the version check (423 wins over 412); a stale version changes nothing (TASK-03). Absent
 * fields stay unchanged; `null` clears `description`, `assignee_user_id`, `due_date` and
 * `parent_task_id`.
 *
 * - `status_id` must be a status of the task's project (DB-T04); any status is a valid target
 *   (ADR-021 §4). A task whose status changes goes to the end of its new column.
 * - `assignee_user_id` must be eligible (TaskAssignments): anyone else is 422 `exists`, exactly like
 *   an unknown user.
 * - `label_ids` replaces the label set; every label must belong to the project.
 * - `parent_task_id` must be a live task of the project that keeps the tree acyclic and at most
 *   three levels deep (TASK-01, TASK-02).
 *
 * A real change increments `version`; a no-op keeps it.
 */
final class UpdateTask
{
    /**
     * @param  array{title?: string, description?: string|null, status_id?: string, priority?: string, assignee_user_id?: string|null, due_date?: string|null, label_ids?: list<string>, parent_task_id?: string|null}  $changes
     *
     * @throws StaleVersionException
     */
    public function handle(Task $task, User $actor, int $expectedVersion, array $changes): Task
    {
        return DB::transaction(function () use ($task, $actor, $expectedVersion, $changes): Task {
            $project = ProjectWriteLock::project($task->project, $actor, 'contribute');
            $before = [];
            $labelsChanged = false;

            $updated = OptimisticLock::update($task, $expectedVersion, function (Task $locked) use ($project, $changes, $expectedVersion, &$before, &$labelsChanged): void {
                $before = ['status_id' => $locked->status_id, 'assignee_user_id' => $locked->assignee_user_id];

                if (array_key_exists('title', $changes)) {
                    $locked->title = $changes['title'];
                }

                if (array_key_exists('description', $changes)) {
                    $locked->description = $changes['description'];
                }

                if (array_key_exists('priority', $changes)) {
                    $locked->priority = TaskPriority::from($changes['priority']);
                }

                if (array_key_exists('due_date', $changes)) {
                    // Compare as dates, so re-sending the stored date is not a change.
                    if ($locked->due_date?->toDateString() !== $changes['due_date']) {
                        $locked->due_date = $changes['due_date'] === null ? null : Carbon::createFromFormat('!Y-m-d', $changes['due_date']);
                    }
                }

                if (array_key_exists('status_id', $changes) && $changes['status_id'] !== $locked->status_id) {
                    if (! $project->statuses()->whereKey($changes['status_id'])->exists()) {
                        throw ValidationException::withMessages(['status_id' => 'exists']);
                    }

                    $locked->status_id = $changes['status_id'];
                    $locked->position = TaskOrder::next($project->id, $changes['status_id']);
                }

                if (array_key_exists('assignee_user_id', $changes) && $changes['assignee_user_id'] !== $locked->assignee_user_id) {
                    $assigneeId = $changes['assignee_user_id'];

                    if ($assigneeId !== null && ! TaskAssignments::eligible($project->id, $assigneeId)) {
                        throw ValidationException::withMessages(['assignee_user_id' => 'exists']);
                    }

                    $locked->assignee_user_id = $assigneeId;
                }

                if (array_key_exists('parent_task_id', $changes) && $changes['parent_task_id'] !== $locked->parent_task_id) {
                    if ($changes['parent_task_id'] !== null) {
                        TaskHierarchy::assertParent($project, $locked, $changes['parent_task_id']);
                    }

                    $locked->parent_task_id = $changes['parent_task_id'];
                }

                if (array_key_exists('label_ids', $changes)) {
                    TaskLabels::assertInProject($project, $changes['label_ids']);

                    // The label set is part of the task, but not a column: mark the row changed
                    // explicitly, so OptimisticLock saves it with the next version (an updated_at in
                    // the same second as the stored one would not count as a change).
                    if (TaskLabels::sync($locked, $changes['label_ids'])) {
                        $labelsChanged = true;
                        $locked->version = $expectedVersion + 1;
                    }
                }
            }, lock: Task::WRITE_LOCK);

            if ($updated->wasChanged()) {
                self::log($updated, $actor, $before, $labelsChanged);
            }

            return $updated->setRelation('project', $project);
        });
    }

    /**
     * IDs and field names only: titles and descriptions may be private (ADR-020 §9).
     *
     * @param  array{status_id?: string, assignee_user_id?: string|null}  $before
     */
    private static function log(Task $task, User $actor, array $before, bool $labelsChanged): void
    {
        $context = [
            'team_id' => $task->team_id,
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'actor_id' => $actor->id,
        ];

        $fields = array_values(array_diff(array_keys($task->getChanges()), ['version', 'updated_at', 'position']));

        if ($labelsChanged) {
            $fields[] = 'label_ids';
        }

        Log::info('task.updated', [...$context, 'fields' => $fields]);

        if ($task->wasChanged('status_id')) {
            Log::info('task.status_changed', [...$context, 'from' => $before['status_id'] ?? null, 'to' => $task->status_id]);
        }

        if ($task->wasChanged('assignee_user_id')) {
            Log::info('task.assignee_changed', [...$context, 'from' => $before['assignee_user_id'] ?? null, 'to' => $task->assignee_user_id]);
        }
    }
}
