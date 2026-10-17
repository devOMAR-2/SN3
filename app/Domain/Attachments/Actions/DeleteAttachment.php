<?php

namespace App\Domain\Attachments\Actions;

use App\Domain\Activity\Activity;
use App\Domain\Attachments\AttachmentFiles;
use App\Domain\Collaboration\AuthoredContent;
use App\Domain\Projects\ProjectWriteLock;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use Illuminate\Support\Facades\DB;

/**
 * Removes an attachment and its file (API_SPEC.md §6.3 DELETE "Uploader or Owner/Project Manager";
 * CORE-008, ADR-027 §5).
 *
 * Locks team → project (423 when archived, 404 when no longer visible) → task → attachment, and
 * re-checks on the locked state that the actor may still delete it (409 otherwise). The row is marked
 * `deleted_at` together with the `attachment.removed` activity entry, so the attachment is gone from
 * every read path and from the quota at once; after commit the file is deleted and then the row
 * (AttachmentFiles::finish), and `attachments:sweep` finishes the job if the storage was unavailable.
 */
final class DeleteAttachment
{
    public function __construct(private readonly AttachmentFiles $files) {}

    public function handle(TaskAttachment $attachment, User $actor): void
    {
        $removed = DB::transaction(function () use ($attachment, $actor): TaskAttachment {
            $project = ProjectWriteLock::project($attachment->task->project, $actor, 'view');
            $task = $project->tasks()->whereKey($attachment->task_id)->lock(Task::WRITE_LOCK)->firstOrFail();
            $locked = $task->attachments()->live()->whereKey($attachment->id)->lockForUpdate()->firstOrFail();

            if (! AuthoredContent::mayDelete($actor, $project, $locked->uploaded_by)) {
                throw new DomainConflictException('Attachment access changed while the request waited; reload and try again.');
            }

            $locked->deleted_at = now();
            $locked->save();

            Activity::task($task, $actor, 'attachment.removed', [
                'attachment_id' => $locked->id,
                'moderated' => AuthoredContent::moderates($actor, $project, $locked->uploaded_by),
            ]);

            return $locked;
        });

        DB::afterCommit(fn () => $this->files->finish($removed));
    }
}
