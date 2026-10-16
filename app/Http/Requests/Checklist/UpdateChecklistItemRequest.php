<?php

namespace App\Http\Requests\Checklist;

use App\Domain\Tasks\Checklist;

/**
 * PATCH /api/v1/tasks/{task}/checklist/{item} (API_SPEC.md §6.2 "Edit text, checked, position"):
 * `text` (1–300), `checked` (strict boolean) and `position` (the 0-based index to move to).
 */
class UpdateChecklistItemRequest extends ChecklistRequest
{
    /** @var list<string> */
    public const FIELDS = ['text', 'checked', 'position'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'text' => ['sometimes', 'required', 'string', 'min:1', 'max:'.Checklist::TEXT_MAX],
            'checked' => ['sometimes', 'required', 'boolean:strict'],
            'position' => ['sometimes', 'required', 'integer', 'min:0', 'max:'.(Checklist::MAX_ITEMS - 1)],
        ];
    }

    /**
     * @return array{text?: string, checked?: bool, position?: int}
     */
    public function changes(): array
    {
        $validated = $this->validated();
        $changes = [];

        if (isset($validated['text']) && is_string($validated['text'])) {
            $changes['text'] = $validated['text'];
        }

        if (isset($validated['checked']) && is_bool($validated['checked'])) {
            $changes['checked'] = $validated['checked'];
        }

        if (isset($validated['position']) && is_numeric($validated['position'])) {
            $changes['position'] = (int) $validated['position'];
        }

        return $changes;
    }
}
