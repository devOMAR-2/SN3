<?php

namespace App\Http\Requests\Lifecycle;

/**
 * DELETE /api/v1/projects/{project}: move to the 30-day trash. Owner or Project Manager. Requires If-Match.
 */
class TrashProjectRequest extends LifecycleRequest
{
    protected function ability(): string
    {
        return 'delete';
    }

    protected function resource(): string
    {
        return 'project';
    }
}
