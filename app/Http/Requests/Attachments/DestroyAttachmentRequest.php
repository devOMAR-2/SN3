<?php

namespace App\Http\Requests\Attachments;

use App\Domain\Collaboration\AuthoredContent;
use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\TaskAttachment;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DELETE /api/v1/attachments/{attachment} (API_SPEC.md §6.3 "Uploader or Owner/Project Manager";
 * CORE-008, ADR-027 §5). No body; the right is checked before validation (403 whatever the body).
 */
class DestroyAttachmentRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = [];

    public function authorize(): bool
    {
        $attachment = $this->route('attachment');
        $user = $this->user();

        return $attachment instanceof TaskAttachment
            && $user instanceof User
            && AuthoredContent::mayDelete($user, $attachment->task->project, $attachment->uploaded_by);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
