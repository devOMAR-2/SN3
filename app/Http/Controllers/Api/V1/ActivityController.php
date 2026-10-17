<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Activity\ActivityFeed;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPagination;
use App\Http\Resources\ActivityResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Activity timelines (API_SPEC.md §4 `GET /projects/{id}/activity`, §6.1 `GET /tasks/{id}/activity`,
 * §9 `GET /me/activity`; CORE-008, ADR-027 §4). `{project}` and `{task}` resolve through
 * AccessibleProjects and AccessibleTasks, so a hidden project's timeline is a 404 like a random ID.
 * Newest first, cursor-paginated.
 */
class ActivityController extends Controller
{
    public function task(Request $request, Task $task, ActivityFeed $feed): JsonResponse
    {
        Gate::authorize('view', $task->project);

        return self::page($request, $feed->forTask($task));
    }

    public function project(Request $request, Project $project, ActivityFeed $feed): JsonResponse
    {
        Gate::authorize('view', $project);

        return self::page($request, $feed->forProject($project));
    }

    public function mine(Request $request, ActivityFeed $feed): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return self::page($request, $feed->forActor($user));
    }

    /**
     * @param  Builder<TaskActivity>  $query
     */
    private static function page(Request $request, Builder $query): JsonResponse
    {
        return CursorPagination::respond($request, $query, fn (TaskActivity $entry): array => ActivityResource::make($entry));
    }
}
