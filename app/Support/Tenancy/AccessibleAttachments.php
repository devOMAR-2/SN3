<?php

namespace App\Support\Tenancy;

use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Resolves `{attachment}` for a user (API_SPEC.md §6.3, ARCHITECTURE.md §5.3; CORE-008, ADR-027 §5).
 * An attachment is reachable only through a live task of a project the user can see right now
 * (AccessibleTasks), so an attachment of a hidden, trashed or foreign project, of a trashed task, one
 * being removed, a random and a malformed ID are all the same 404 (DB-T01, DB-T02, DB-T08, AUTH-05).
 * Nothing is cached: a member removed from a private project loses every download at once.
 */
final class AccessibleAttachments
{
    public function __construct(private readonly AccessibleTasks $tasks) {}

    /**
     * @throws ModelNotFoundException<TaskAttachment>
     */
    public function findOrFail(User $user, string $id): TaskAttachment
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException)->setModel(TaskAttachment::class, [$id]);
        }

        $attachment = TaskAttachment::query()
            ->live()
            ->whereKey(strtolower($id))
            ->whereHas('task', function (Builder $tasks) use ($user): void {
                /** @var Builder<Task> $tasks */
                $this->tasks->constrain($tasks, $user);
            })
            ->first();

        if (! $attachment instanceof TaskAttachment) {
            throw (new ModelNotFoundException)->setModel(TaskAttachment::class, [$id]);
        }

        try {
            $task = $this->tasks->findOrFail($user, $attachment->task_id);
        } catch (ModelNotFoundException) {
            throw (new ModelNotFoundException)->setModel(TaskAttachment::class, [$id]);
        }

        return $attachment->setRelation('task', $task);
    }
}
