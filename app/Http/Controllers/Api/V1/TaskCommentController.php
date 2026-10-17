<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Comments\Actions\CreateComment;
use App\Domain\Comments\Actions\DeleteComment;
use App\Domain\Comments\Actions\UpdateComment;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPagination;
use App\Http\Requests\Comments\DestroyCommentRequest;
use App\Http\Requests\Comments\StoreCommentRequest;
use App\Http\Requests\Comments\UpdateCommentRequest;
use App\Http\Resources\CommentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Task comments (API_SPEC.md §6.2, FR-COL-001, FR-COL-004; CORE-008, ADR-027 §2). `{task}` resolves
 * through AccessibleTasks, so a comment in a hidden project is a 404 like a random ID; a `{comment}`
 * of another task is a 404 too. Reading needs access to the project, writing needs `contribute`;
 * editing is the author's, deleting the author's or a moderator's. The list is newest first
 * (`created_at desc, id desc`, §1.4) and leaves deleted comments out.
 */
class TaskCommentController extends Controller
{
    public function index(Request $request, Task $task): JsonResponse
    {
        Gate::authorize('view', $task->project);

        $query = $task->comments()->getQuery()->with('mentions:id')->orderByDesc('created_at')->orderByDesc('id');

        return CursorPagination::respond($request, $query, fn (TaskComment $comment): array => CommentResource::make($comment));
    }

    public function store(StoreCommentRequest $request, Task $task, CreateComment $createComment): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return self::respond($createComment->handle($task, $user, $request->body()), 201);
    }

    public function update(UpdateCommentRequest $request, Task $task, string $comment, UpdateComment $updateComment): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return self::respond($updateComment->handle($task, $request->comment(), $user, $request->body()));
    }

    public function destroy(DestroyCommentRequest $request, Task $task, string $comment, DeleteComment $deleteComment): Response
    {
        /** @var User $user */
        $user = $request->user();

        $deleteComment->handle($task, $request->comment(), $user);

        return response()->noContent();
    }

    private static function respond(TaskComment $comment, int $status = 200): JsonResponse
    {
        return ApiResponse::data(CommentResource::make($comment->load('mentions:id')), $status);
    }
}
