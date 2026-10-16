<?php

namespace App\Http\Requests\Tasks;

/**
 * DELETE /api/v1/tasks/{task} (API_SPEC.md §6.1 "Project writer"). No body; requires `If-Match`.
 */
class DestroyTaskRequest extends TaskActionRequest
{
    protected function ability(): string
    {
        return 'contribute';
    }
}
