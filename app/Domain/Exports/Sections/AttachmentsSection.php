<?php

namespace App\Domain\Exports\Sections;

use App\Domain\Attachments\AttachmentStorage;
use App\Domain\Exports\TeamExportContext;
use App\Domain\Exports\TeamExportFiles;
use App\Domain\Exports\TeamExportSection;
use App\Models\Task;
use App\Models\TaskAttachment;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Throwable;

/**
 * `data/attachments.json` and `files/<id>`: the attachment manifest and the files themselves
 * (DATABASE.md §13 "attachment manifests and signed downloadable attachment archive", API_SPEC.md
 * §13 "authorized attachments", ADR-024 §8; CORE-008, ADR-027 §7).
 *
 * Live attachments of the exported, live tasks only. Each record names its archive entry in `file`
 * (`files/<id>`, never the original name) and carries the stored SHA-256, so a consumer can check
 * every file. A file the storage cannot find is recorded with `file: null` instead of failing the
 * whole export. files() streams exactly the files records() announced.
 */
final class AttachmentsSection implements TeamExportFiles, TeamExportSection
{
    /** @var array<string, array{disk: string, key: string}> entry => where the file is */
    private array $files = [];

    public function __construct(private readonly AttachmentStorage $storage) {}

    public function name(): string
    {
        return 'attachments';
    }

    public function records(TeamExportContext $context): iterable
    {
        $this->files = [];

        $attachments = TaskAttachment::query()
            ->live()
            ->where('team_id', $context->team->id)
            ->whereIn('project_id', $context->projectIds())
            ->whereHas('task', function (Builder $tasks): void {
                /** @var Builder<Task> $tasks */
                $tasks->whereNull('tasks.deleted_at');
            })
            ->lazyById(500);

        foreach ($attachments as $attachment) {
            $entry = 'files/'.$attachment->id;
            $present = $this->exists($attachment);

            if ($present) {
                $this->files[$entry] = ['disk' => $attachment->storage_disk, 'key' => $attachment->storage_key];
            }

            yield [
                'id' => $attachment->id,
                'task_id' => $attachment->task_id,
                'uploaded_by' => $attachment->uploaded_by,
                'filename' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
                'sha256' => $attachment->sha256Hex(),
                'file' => $present ? $entry : null,
                'created_at' => TeamExportContext::timestamp($attachment->created_at),
            ];
        }
    }

    public function files(TeamExportContext $context): iterable
    {
        foreach ($this->files as $entry => $location) {
            $stream = $this->storage->disk($location['disk'])->readStream($location['key']);

            if (! is_resource($stream)) {
                throw new RuntimeException('An attachment file disappeared during the export.');
            }

            yield $entry => $stream;
        }
    }

    private function exists(TaskAttachment $attachment): bool
    {
        try {
            return $this->storage->diskOf($attachment)->exists($attachment->storage_key);
        } catch (Throwable) {
            return false;
        }
    }
}
