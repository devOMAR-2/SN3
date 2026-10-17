<?php

namespace App\Http\Requests\Invitations;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body-less invitation requests (API_SPEC.md §3.1): `DELETE /teams/{team}/invitations/{invitation}`
 * (Owner/Admin: 403 for everyone else, whatever the body) and `POST /invitations/{token}/accept`
 * (any signed-in, verified user). Any body field is `unknown_field`.
 */
class InvitationActionRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = [];

    public function authorize(): bool
    {
        $team = $this->route('team');

        return $team === null || ($this->user()?->can('invite', $team) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
