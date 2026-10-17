<?php

namespace App\Listeners;

use App\Domain\Attachments\AttachmentFiles;
use App\Domain\Trash\Events\ProjectPurged;
use App\Domain\Trash\Events\TeamPurged;

/**
 * The trash purge removes attachment rows by cascade; this deletes their files (DATABASE.md §13
 * "stored files … their tasks must delete them along with the purge"; ADR-022 §5; CORE-008,
 * ADR-027 §5). It runs synchronously in the purge, after the row's delete committed. A storage
 * failure is logged and left to `attachments:sweep`, which deletes prefixes whose project or team
 * no longer exists.
 */
final class DeletePurgedAttachmentFiles
{
    public function __construct(private readonly AttachmentFiles $files) {}

    public function handle(ProjectPurged $event): void
    {
        $this->files->purgeProject($event->teamId, $event->projectId);
    }

    public function handleTeamPurged(TeamPurged $event): void
    {
        $this->files->purgeTeam($event->teamId);
    }
}
