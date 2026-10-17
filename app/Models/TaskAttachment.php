<?php

namespace App\Models;

use Database\Factories\TaskAttachmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file attached to one task (DATABASE.md §5, API_SPEC.md §6.3, CORE-008, ADR-027 §5). Reach
 * attachments only through a task the caller may see (`$task->attachments()`, or the `{attachment}`
 * binding, AccessibleAttachments).
 *
 * The stored object lives on the private attachments disk under an opaque random key; `original_name`
 * is metadata only and `mime_type` is the sniffed type. A row with `deleted_at` is being removed: it is
 * hidden everywhere (the `live` scope), out of the quota, and `attachments:sweep` finishes deleting its
 * file and row if the request could not.
 *
 * @property string $id
 * @property string $team_id
 * @property string $project_id
 * @property string $task_id
 * @property string $uploaded_by
 * @property string $storage_disk
 * @property string $storage_key
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string|null $sha256 raw 32-byte digest
 * @property Carbon|null $created_at
 * @property Carbon|null $deleted_at
 * @property-read Task $task
 */
class TaskAttachment extends Model
{
    /** @use HasFactory<TaskAttachmentFactory> */
    use HasFactory, HasUuids;

    /** Attachments are immutable; there is no `updated_at` (DATABASE.md §5). */
    public const UPDATED_AT = null;

    /**
     * Everything is set by the attachment actions.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var list<string>
     */
    protected $hidden = ['storage_disk', 'storage_key', 'sha256'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Attachments that are not being removed.
     *
     * @param  Builder<TaskAttachment>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('task_attachments.deleted_at');
    }

    /** The SHA-256 digest as lower-case hex. */
    public function sha256Hex(): ?string
    {
        $digest = $this->sha256Bytes();

        return $digest === null ? null : bin2hex($digest);
    }

    /**
     * The raw digest. PostgreSQL `bytea` arrives as a stream from PDO.
     */
    public function sha256Bytes(): ?string
    {
        $value = $this->getAttributes()['sha256'] ?? null;

        if (is_resource($value)) {
            $value = stream_get_contents($value);
            $this->setRawAttributes([...$this->getAttributes(), 'sha256' => $value], true);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
