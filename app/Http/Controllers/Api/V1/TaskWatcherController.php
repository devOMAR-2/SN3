<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notifications\Actions\UnwatchTask;
use App\Domain\Notifications\Actions\WatchTask;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\WatchTaskRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Watching a task (API_SPEC.md §6.1; CORE-009, ADR-029 §1). `{task}` resolves through AccessibleTasks,
 * so a hidden task is a 404 like a random ID. `POST` answers `201` with the new watch, `200` with the
 * existing one; `DELETE …/watchers/me` answers `204`, or `404` when the caller does not watch the task.
 */
class TaskWatcherController extends Controller
{
    public function store(WatchTaskRequest $request, WatchTask $watchTask): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        ['watch' => $watch, 'created' => $created] = $watchTask->handle($request->task(), $user);

        return ApiResponse::data($watch, $created ? 201 : 200);
    }

    public function destroy(WatchTaskRequest $request, UnwatchTask $unwatchTask): Response
    {
        /** @var User $user */
        $user = $request->user();

        $unwatchTask->handle($request->task(), $user);

        return response()->noContent();
    }
}
