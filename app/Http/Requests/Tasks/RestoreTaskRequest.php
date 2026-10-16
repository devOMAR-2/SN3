<?php

namespace App\Http\Requests\Tasks;

/**
 * POST /api/v1/tasks/{task}/restore (API_SPEC.md §6.1 "Project Manager/Owner"). No body, no
 * `If-Match`. `{trashedTask}` already resolves only for callers who may restore (AccessibleTasks).
 */
class RestoreTaskRequest extends TaskActionRequest
{
    protected function ability(): string
    {
        return 'restoreTasks';
    }

    protected function parameter(): string
    {
        return 'trashedTask';
    }
}
