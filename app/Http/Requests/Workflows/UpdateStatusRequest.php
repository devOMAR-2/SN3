<?php

namespace App\Http\Requests\Workflows;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/projects/{project}/statuses/{status} (API_SPEC.md §5): partial update of `name`,
 * `category` and `color`, with the create rules; `color: null` clears it. `position` is read-only
 * (`unknown_field`): the order changes only through `PUT /statuses/order`. Requires `If-Match`.
 */
class UpdateStatusRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = ['name', 'category', 'color'];

    /** Owner or project manager (ProjectPolicy::manageWorkflow): 403 before validation. */
    public function authorize(): bool
    {
        return $this->user()?->can('manageWorkflow', $this->route('project')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return StoreStatusRequest::fieldRules(required: false);
    }

    protected function prepareForValidation(): void
    {
        StoreStatusRequest::normalize($this);
    }
}
