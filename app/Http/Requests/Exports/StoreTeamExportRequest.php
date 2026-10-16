<?php

namespace App\Http\Requests\Exports;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/teams/{team}/exports (API_SPEC.md §3, §18; ADR-024). Owner only. The request takes
 * no body, so any body field is `unknown_field` (§1.2), and it authorises before validating, so a
 * caller without the right gets 403 whatever the body (AUTH-04).
 */
class StoreTeamExportRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = [];

    public function authorize(): bool
    {
        return $this->user()?->can('export', $this->route('team')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
