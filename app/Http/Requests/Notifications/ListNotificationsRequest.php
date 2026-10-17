<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query of GET /api/v1/notifications (API_SPEC.md §9 "Paginated own inbox"; CORE-009, ADR-029 §3):
 * `status=all` (default) or `status=unread`, plus `limit` and `cursor`.
 */
class ListNotificationsRequest extends FormRequest
{
    /** @var list<string> */
    public const STATUSES = ['all', 'unread'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->query->all();
    }

    public function unreadOnly(): bool
    {
        return $this->validated('status') === 'unread';
    }
}
