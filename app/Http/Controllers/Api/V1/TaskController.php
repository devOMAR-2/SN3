<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\RestoreTask;
use App\Domain\Tasks\Actions\TrashTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\TaskQuery;
use App\Http\Concurrency\IfMatch;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPagination;
use App\Http\Requests\Tasks\DestroyTaskRequest;
use App\Http\Requests\Tasks\ListTasksRequest;
use App\Http\Requests\Tasks\RestoreTaskRequest;
use App\Http\Requests\Tasks\StoreTaskRequest;
use App\Http\Requests\Tasks\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Http\Responses\ApiResponse;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Tasks (API_SPEC.md §6.1, FR-TASK-001..004, ADR-023): list and create under a project, read, edit,
 * delete and restore by ID.
 *
 * `{project}` resolves through AccessibleProjects and `{task}` through AccessibleTasks
 * (TenancyServiceProvider), so a task in a hidden project is a 404 like a random ID before this
 * controller runs. Reading needs only access to the project; writing needs `contribute` (a Team
 * Viewer is read-only); restoring needs `restoreTasks`.
 *
 * Single-task responses carry the task's `version` as `ETag` (`"<n>"`), the value its PATCH and
 * DELETE expect in `If-Match` (§1.4).
 */
class TaskController extends Controller
{
    public function index(ListTasksRequest $request, Project $project): JsonResponse
    {
        $filters = $request->filters();

        $query = TaskQuery::filter($project->tasks()->getQuery()->with('labels:id'), $project, $filters);
        TaskQuery::sort($query, $filters['sort'] ?? 'created_at');

        return CursorPagination::respond($request, $query, fn (Task $task): array => TaskResource::make($task, $project));
    }

    public function store(StoreTaskRequest $request, Project $project, CreateTask $createTask): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $task = $createTask->handle($project, $user, $request->taskInput());

        return self::respond($task, 201);
    }

    public function show(Request $request, Task $task): JsonResponse
    {
        Gate::authorize('view', $task->project);

        return self::respond($task);
    }

    public function update(UpdateTaskRequest $request, Task $task, UpdateTask $updateTask): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $updated = $updateTask->handle($task, $user, IfMatch::version($request), $request->changes());

        return self::respond($updated);
    }

    public function destroy(DestroyTaskRequest $request, Task $task, TrashTask $trashTask): Response
    {
        /** @var User $user */
        $user = $request->user();

        $trashTask->handle($task, $user, IfMatch::version($request));

        return response()->noContent();
    }

    public function restore(RestoreTaskRequest $request, Task $trashedTask, RestoreTask $restoreTask): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return self::respond($restoreTask->handle($trashedTask, $user));
    }

    private static function respond(Task $task, int $status = 200): JsonResponse
    {
        return ApiResponse::data(TaskResource::make($task, $task->project), $status, ['ETag' => '"'.$task->version.'"']);
    }
}
