<?php

namespace App\Http\Requests\Lifecycle;

/**
 * POST /api/v1/projects/{project}/restore-deleted: Owner or Project Manager (the binding already hides the project from anyone else).
 */
class RestoreDeletedProjectRequest extends LifecycleRequest
{
    protected function ability(): string
    {
        return 'restoreDeleted';
    }

    protected function resource(): string
    {
        return 'trashedProject';
    }
}
