<?php

namespace App\Http\Resources;

use App\Models\TaskAttachment;

/**
 * Attachment metadata (API_SPEC.md §6.3 "List metadata"; CORE-008, ADR-027 §5). `filename` is the
 * sanitised original name, `mime_type` the type sniffed from the content, `sha256` the hex digest of
 * the stored file. `download_url` is the authorized download route, not a storage URL: storage keys
 * and disks never leave the server.
 */
final class AttachmentResource
{
    /**
     * @return array{id: string, task_id: string, uploaded_by: string, filename: string, mime_type: string, size_bytes: int, sha256: string|null, download_url: string, created_at: string|null}
     */
    public static function make(TaskAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'task_id' => $attachment->task_id,
            'uploaded_by' => $attachment->uploaded_by,
            'filename' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size_bytes' => $attachment->size_bytes,
            'sha256' => $attachment->sha256Hex(),
            'download_url' => '/api/v1/attachments/'.$attachment->id.'/download',
            'created_at' => $attachment->created_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
