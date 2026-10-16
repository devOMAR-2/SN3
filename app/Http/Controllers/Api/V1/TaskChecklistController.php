<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tasks\Actions\CreateChecklistItem;
use App\Domain\Tasks\Actions\DeleteChecklistItem;
use App\Domain\Tasks\Actions\UpdateChecklistItem;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPagination;
use App\Http\Requests\Checklist\DestroyChecklistItemRequest;
use App\Http\Requests\Checklist\StoreChecklistItemRequest;
use App\Http\Requests\Checklist\UpdateChecklistItemRequest;
use App\Http\Resources\ChecklistItemResource;
use App\Http\Responses\ApiResponse;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Task checklists (API_SPEC.md §6.2, FR-TASK-007, ADR-023 §7). `{task}` resolves through
 * AccessibleTasks; an `{item}` of another task is a 404. Reading needs access to the project, writing
 * needs `contribute`. The list is in checklist order (`position`, then `id`).
 */
class TaskChecklistController extends Controller
{
    public function index(Request $request, Task $task): JsonResponse
    {
        Gate::authorize('view', $task->project);

        $query = $task->checklistItems()->getQuery()->orderBy('position')->orderBy('id');

        return CursorPagination::respond($request, $query, fn (TaskChecklistItem $item): array => ChecklistItemResource::make($item));
    }

    public function store(StoreChecklistItemRequest $request, Task $task, CreateChecklistItem $createItem): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::data(ChecklistItemResource::make($createItem->handle($task, $user, $request->text())), 201);
    }

    public function update(UpdateChecklistItemRequest $request, Task $task, string $item, UpdateChecklistItem $updateItem): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::data(ChecklistItemResource::make($updateItem->handle($task, self::item($task, $item), $user, $request->changes())));
    }

    public function destroy(DestroyChecklistItemRequest $request, Task $task, string $item, DeleteChecklistItem $deleteItem): Response
    {
        /** @var User $user */
        $user = $request->user();

        $deleteItem->handle($task, self::item($task, $item), $user);

        return response()->noContent();
    }

    /**
     * @throws ModelNotFoundException<TaskChecklistItem>
     */
    private static function item(Task $task, string $id): TaskChecklistItem
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException)->setModel(TaskChecklistItem::class);
        }

        return $task->checklistItems()->whereKey(strtolower($id))->firstOrFail();
    }
}
