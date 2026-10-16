<?php

namespace App\Http\Requests\Lifecycle;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Archive, unarchive, trash and recover requests for teams and projects (API_SPEC.md §3, §4;
 * ADR-022). They take no body, so any body field is `unknown_field` (§1.2), and they authorise
 * before validating, so a caller without the right gets 403 whatever the body (AUTH-04).
 */
abstract class LifecycleRequest extends FormRequest
{
    use RejectsUnknownFields;

    /** @var list<string> */
    public const FIELDS = [];

    /** The policy ability the request needs. */
    abstract protected function ability(): string;

    /** The route parameter holding the team or project. */
    abstract protected function resource(): string;

    public function authorize(): bool
    {
        return $this->user()?->can($this->ability(), $this->route($this->resource())) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
