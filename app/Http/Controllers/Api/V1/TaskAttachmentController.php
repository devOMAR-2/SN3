<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Attachments\Actions\UploadAttachment;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPagination;
use App\Http\Requests\Attachments\StoreAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Attachments of a task (API_SPEC.md §6.3; CORE-008, ADR-027 §5): list metadata (project viewers)
 * and upload (project writers; `Idempotency-Key`, rate-limited). `{task}` resolves through
 * AccessibleTasks, so a hidden project's attachments are a 404 like a random ID. Newest first.
 */
class TaskAttachmentController extends Controller
{
    public function index(Request $request, Task $task): JsonResponse
    {
        Gate::authorize('view', $task->project);

        $query = $task->attachments()->getQuery()->live()->orderByDesc('created_at')->orderByDesc('id');

        return CursorPagination::respond($request, $query, fn (TaskAttachment $attachment): array => AttachmentResource::make($attachment));
    }

    public function store(StoreAttachmentRequest $request, Task $task, UploadAttachment $upload): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $attachment = $upload->handle($task, $user, $request->path(), $request->filename(), $request->mimeType());

        return ApiResponse::data(AttachmentResource::make($attachment), 201, ['Location' => '/api/v1/attachments/'.$attachment->id.'/download']);
    }
}
