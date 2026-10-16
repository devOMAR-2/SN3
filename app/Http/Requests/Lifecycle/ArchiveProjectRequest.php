<?php

namespace App\Http\Requests\Lifecycle;

/**
 * POST /api/v1/projects/{project}/archive and /restore (unarchive): Owner or Project Manager. Requires If-Match.
 */
class ArchiveProjectRequest extends LifecycleRequest
{
    protected function ability(): string
    {
        return 'archive';
    }

    protected function resource(): string
    {
        return 'project';
    }
}
