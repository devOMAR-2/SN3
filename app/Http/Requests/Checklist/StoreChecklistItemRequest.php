<?php

namespace App\Http\Requests\Checklist;

use App\Domain\Tasks\Checklist;

/**
 * POST /api/v1/tasks/{task}/checklist (API_SPEC.md §6.2): `{ "text" }`, trimmed, 1–300 characters.
 * The item goes last; at most 100 items per task (409 beyond).
 */
class StoreChecklistItemRequest extends ChecklistRequest
{
    /** @var list<string> */
    public const FIELDS = ['text'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'min:1', 'max:'.Checklist::TEXT_MAX],
        ];
    }

    public function text(): string
    {
        $text = $this->validated('text');

        return is_string($text) ? $text : '';
    }
}
