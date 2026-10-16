<?php

namespace App\Http\Requests\Teams;

use App\Domain\Teams\TeamRole;
use App\Http\Requests\Concerns\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/teams/{team}/members/{user} (API_SPEC.md §3). The Owner role is never assigned
 * here; it moves only through POST /ownership-transfer.
 */
class UpdateMemberRoleRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = ['role'];

    /** Only the Owner may change roles: everyone else gets 403 before validation. */
    public function authorize(): bool
    {
        return $this->user()?->can('changeRoles', $this->route('team')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in([TeamRole::Admin->value, TeamRole::Member->value, TeamRole::Viewer->value])],
        ];
    }
}
