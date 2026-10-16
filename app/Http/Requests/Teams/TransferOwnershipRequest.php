<?php

namespace App\Http\Requests\Teams;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/teams/{team}/ownership-transfer: `{ "new_owner_user_id": "uuid" }` (API_SPEC.md §3).
 */
class TransferOwnershipRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = ['new_owner_user_id'];

    public function authorize(): bool
    {
        return $this->user()?->can('transferOwnership', $this->route('team')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'new_owner_user_id' => ['required', 'string', 'uuid'],
        ];
    }
}
