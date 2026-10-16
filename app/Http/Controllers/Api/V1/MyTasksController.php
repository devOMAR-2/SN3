<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tasks\TaskQuery;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPagination;
use App\Http\Requests\Tasks\ListMyTasksRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\User;
use App\Support\Tenancy\AccessibleTasks;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/me/tasks (API_SPEC.md §2 "Authorized assigned tasks across teams"; ADR-023 §3): the
 * caller's assigned tasks in every project they can see right now, across all their teams. The
 * AccessibleProjects predicate is applied before sorting and pagination, so a task of a project the
 * caller lost access to is never listed, counted or paged over.
 */
class MyTasksController extends Controller
{
    public function __invoke(ListMyTasksRequest $request, AccessibleTasks $tasks): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = $tasks->constrain(Task::query(), $user)
            ->where('assignee_user_id', $user->id)
            ->with(['labels:id', 'project:id,project_key']);
        TaskQuery::sort($query, $request->sort());

        return CursorPagination::respond($request, $query, fn (Task $task): array => TaskResource::make($task, $task->project));
    }
}
