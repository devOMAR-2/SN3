<?php

namespace App\Domain\Attachments\Actions;

use App\Domain\Activity\Activity;
use App\Domain\Attachments\AttachmentQuota;
use App\Domain\Attachments\AttachmentStorage;
use App\Domain\Projects\ProjectWriteLock;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Stores an uploaded file as an attachment of a task (API_SPEC.md §6.3 POST "Project writer";
 * CORE-008, ADR-027 §5). The request has already checked the size limit, the sniffed type and the
 * file name (StoreAttachmentRequest); this action enforces the team quota and stores the file.
 *
 * 1. **Quota first:** a team already at its quota is refused (413) before anything is written
 *    (acceptance scenario LIM-02).
 * 2. The file is copied to the private disk under a new random key, outside any lock, so a slow
 *    store does not hold the team's write lock.
 * 3. In one transaction: team → project (`contribute`; 423, 404, 409 as for every writer) → task
 *    (trashed meanwhile: 404); the quota is checked again under the team lock, which every
 *    attachment writer of the team takes first, so concurrent uploads cannot overshoot it together;
 *    then the row and the `attachment.added` activity entry.
 * 4. **No orphan file survives a failure:** when the transaction (or the request's surrounding
 *    `idempotent` transaction) rolls back, the stored file is deleted; `attachments:sweep` removes
 *    any file a crash left without a row.
 */
final class UploadAttachment
{
    public function __construct(private readonly AttachmentStorage $storage) {}

    public function handle(Task $task, User $actor, string $path, string $filename, string $mimeType): TaskAttachment
    {
        $size = filesize($path);
        $sha256 = hash_file('sha256', $path, true);

        if ($size === false || $sha256 === false) {
            throw new RuntimeException('Cannot read the uploaded file.');
        }

        AttachmentQuota::assertRoomFor($task->team_id, $size);

        $diskName = $this->storage->diskName();
        $key = $this->storage->newKey($task->team_id, $task->project_id);
        $this->write($diskName, $key, $path);

        $discard = function () use ($diskName, $key): void {
            try {
                $this->storage->disk($diskName)->delete($key);
            } catch (Throwable) {
                // The sweeper removes it later; IDs only.
                Log::warning('attachment.discard_failed', ['storage_key_prefix' => dirname($key)]);
            }
        };

        // The surrounding transaction (the `idempotent` middleware's) may still roll back after this
        // action has committed its own part.
        if (DB::transactionLevel() > 0) {
            DB::afterRollBack($discard);
        }

        try {
            return DB::transaction(function () use ($task, $actor, $diskName, $key, $filename, $mimeType, $size, $sha256): TaskAttachment {
                $project = ProjectWriteLock::project($task->project, $actor, 'contribute');
                $locked = $project->tasks()->whereKey($task->id)->lock(Task::WRITE_LOCK)->firstOrFail();

                AttachmentQuota::assertRoomFor($project->team_id, $size);

                $attachment = new TaskAttachment;
                $attachment->forceFill([
                    'team_id' => $locked->team_id,
                    'project_id' => $locked->project_id,
                    'task_id' => $locked->id,
                    'uploaded_by' => $actor->id,
                    'storage_disk' => $diskName,
                    'storage_key' => $key,
                    'original_name' => $filename,
                    'mime_type' => $mimeType,
                    'size_bytes' => $size,
                    'sha256' => DB::raw("decode('".bin2hex($sha256)."', 'hex')"),
                ])->save();

                Activity::task($locked, $actor, 'attachment.added', [
                    'attachment_id' => $attachment->id,
                    'mime_type' => $mimeType,
                    'size_bytes' => $size,
                ]);

                return $attachment->setRawAttributes([...$attachment->getAttributes(), 'sha256' => $sha256], true);
            });
        } catch (Throwable $e) {
            $discard();

            throw $e;
        }
    }

    private function write(string $diskName, string $key, string $path): void
    {
        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Cannot read the uploaded file.');
        }

        try {
            $this->storage->disk($diskName)->writeStream($key, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
