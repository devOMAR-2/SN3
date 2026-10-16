<?php

namespace App\Http\Requests\Checklist;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Checklist writes (API_SPEC.md §6.2, "Project writer"): the right is checked before validation, so
 * a Team Viewer gets 403 whatever the body (AUTH-04).
 */
abstract class ChecklistRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = [];

    public function authorize(): bool
    {
        $task = $this->route('task');

        return $task instanceof Task && ($this->user()?->can('contribute', $task->project) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $text = $this->input('text');

        if (is_string($text)) {
            $this->merge(['text' => trim($text)]);
        }
    }
}
