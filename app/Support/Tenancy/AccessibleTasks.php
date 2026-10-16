<?php

namespace App\Support\Tenancy;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Resolves tasks for a user (CORE-005, ADR-023 §3). A task is reachable only through a project the
 * user can see (AccessibleProjects::constrain()), so a task in a hidden, trashed or foreign project
 * is the same 404 as a random or malformed ID (DB-T01, DB-T02, AUTH-01).
 *
 * The task comes back with its project resolved by AccessibleProjects (the viewer's own team and
 * project memberships eager-loaded), so policy checks on it cost no further queries.
 */
final class AccessibleTasks
{
    public function __construct(private readonly AccessibleProjects $projects) {}

    /**
     * Restricts a task query to tasks of projects the user can see.
     *
     * @param  Builder<Task>  $tasks
     * @return Builder<Task>
     */
    public function constrain(Builder $tasks, User $user): Builder
    {
        return $tasks->whereHas('project', function (Builder $projects) use ($user): void {
            /** @var Builder<Project> $projects */
            $this->projects->constrain($projects, $user);
        });
    }

    /**
     * A live task the user can see.
     *
     * @throws ModelNotFoundException<Task>
     */
    public function findOrFail(User $user, string $id): Task
    {
        return $this->resolve($user, $id, Task::query());
    }

    /**
     * A trashed task, only for a user who may restore it (the Team Owner or a project manager,
     * `ProjectPolicy::restoreTasks`). Everyone else gets the 404 a missing task gets: the task trash
     * is invisible to anyone who cannot act on it, as the project trash is (ADR-022 §4).
     *
     * @throws ModelNotFoundException<Task>
     */
    public function findTrashedOrFail(User $user, string $id): Task
    {
        $task = $this->resolve($user, $id, Task::onlyTrashed());

        if (! Gate::forUser($user)->allows('restoreTasks', $task->project)) {
            throw (new ModelNotFoundException)->setModel(Task::class, [$id]);
        }

        return $task;
    }

    /**
     * @param  Builder<Task>  $query
     *
     * @throws ModelNotFoundException<Task>
     */
    private function resolve(User $user, string $id, Builder $query): Task
    {
        // Comparing a non-UUID with a uuid column is a PostgreSQL error, not a miss.
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException)->setModel(Task::class, [$id]);
        }

        $task = $this->constrain($query, $user)->whereKey(strtolower($id))->first();

        if (! $task instanceof Task) {
            throw (new ModelNotFoundException)->setModel(Task::class, [$id]);
        }

        // The same AccessibleProjects predicate again, now loading what the policies need.
        $project = $this->projects->query($user)->whereKey($task->project_id)->first();

        if (! $project instanceof Project) {
            throw (new ModelNotFoundException)->setModel(Task::class, [$id]);
        }

        return $task->setRelation('project', $project);
    }
}
