<?php

namespace App\Http\Requests\Invitations;

use App\Domain\Invitations\InvitationType;
use App\Domain\Teams\TeamRole;
use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/teams/{team}/invitations (API_SPEC.md §3.1):
 * `{ "type": "email", "email": "a@example.com", "role": "member" }` or `{ "type": "link", "role": "member" }`.
 *
 * - Owner/Admin only: everyone else gets 403 whatever the body (AUTH-04).
 * - `email` is required for `email` invitations and prohibited for links; it is trimmed and
 *   lower-cased like account emails.
 * - `role` is `member` or `viewer`, or `admin` on an email invitation (who may grant it is the
 *   `inviteAs` ability, checked by the controller: 403). `owner` is never valid.
 */
class StoreInvitationRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = ['type', 'email', 'role'];

    public function authorize(): bool
    {
        return $this->user()?->can('invite', $this->route('team')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isEmail = $this->input('type') === InvitationType::Email->value;
        $roles = $isEmail
            ? [TeamRole::Admin->value, TeamRole::Member->value, TeamRole::Viewer->value]
            : [TeamRole::Member->value, TeamRole::Viewer->value];

        return [
            'type' => ['required', 'string', Rule::in(array_column(InvitationType::cases(), 'value'))],
            'email' => $isEmail
                ? ['required', 'string', 'max:255', 'email:rfc']
                : ['prohibited'],
            'role' => ['required', 'string', Rule::in($roles)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => User::normalizeEmail($email)]);
        }
    }

    public function type(): InvitationType
    {
        return InvitationType::from($this->string('type')->toString());
    }

    public function role(): TeamRole
    {
        return TeamRole::from($this->string('role')->toString());
    }

    public function email(): ?string
    {
        return $this->type() === InvitationType::Email ? $this->string('email')->toString() : null;
    }
}
