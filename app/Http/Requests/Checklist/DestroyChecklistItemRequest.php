<?php

namespace App\Http\Requests\Checklist;

/**
 * DELETE /api/v1/tasks/{task}/checklist/{item} (API_SPEC.md §6.2): no body.
 */
class DestroyChecklistItemRequest extends ChecklistRequest
{
    /** @var list<string> */
    public const FIELDS = [];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
