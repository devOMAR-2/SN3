<?php

namespace App\Http\Requests\Notifications;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/notifications/{notification}/read and POST /api/v1/notifications/read-all (API_SPEC.md
 * §9; CORE-009, ADR-029 §3): no body. `{notification}` resolves only to the caller's own visible
 * notifications (Inbox), so anyone else's is a 404 before this runs.
 */
class MarkNotificationsReadRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = [];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
