<?php

namespace App\Domain\Attachments;

use App\Models\TaskAttachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Removes stored attachment files (CORE-008, ADR-027 §5, ADR-022 §5 "stored files are not rows").
 *
 * - **One attachment** (`finish()`): after its deletion committed (the row is marked `deleted_at`),
 *   the file is deleted and then the row. If the storage fails, the marked row stays and
 *   `attachments:sweep` tries again; the attachment is invisible and out of the quota meanwhile.
 * - **A purged project or team** (`purgeProject()`, `purgeTeam()`): its whole key prefix goes, on
 *   every disk attachments may be on (AttachmentStorage::diskNames()), called
 *   by the trash purge once the rows are gone (DeletePurgedAttachmentFiles), and by the sweeper for
 *   prefixes whose project or team no longer exists.
 *
 * Failures are logged with IDs only and never break the caller.
 */
final class AttachmentFiles
{
    public function __construct(private readonly AttachmentStorage $storage) {}

    public function finish(TaskAttachment $attachment): bool
    {
        try {
            $this->storage->diskOf($attachment)->delete($attachment->storage_key);
        } catch (Throwable) {
            Log::warning('attachment.file_delete_failed', ['attachment_id' => $attachment->id, 'team_id' => $attachment->team_id]);

            return false;
        }

        DB::table('task_attachments')->where('id', $attachment->id)->whereNotNull('deleted_at')->delete();

        return true;
    }

    public function purgeProject(string $teamId, string $projectId): bool
    {
        return $this->deletePrefix($this->storage->projectPrefix($teamId, $projectId), ['team_id' => $teamId, 'project_id' => $projectId]);
    }

    public function purgeTeam(string $teamId): bool
    {
        return $this->deletePrefix($this->storage->teamPrefix($teamId), ['team_id' => $teamId]);
    }

    /**
     * Deletes the prefix on every disk attachments may be on (AttachmentStorage::diskNames()).
     *
     * @param  array<string, string>  $context
     */
    private function deletePrefix(string $prefix, array $context): bool
    {
        $done = true;

        foreach ($this->storage->diskNames() as $disk) {
            try {
                $this->storage->disk($disk)->deleteDirectory($prefix);
            } catch (Throwable) {
                Log::warning('attachment.prefix_delete_failed', [...$context, 'disk' => $disk]);
                $done = false;
            }
        }

        return $done;
    }
}
