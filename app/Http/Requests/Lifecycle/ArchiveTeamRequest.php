<?php

namespace App\Http\Requests\Lifecycle;

/**
 * POST /api/v1/teams/{team}/archive and /restore (unarchive): Owner only.
 */
class ArchiveTeamRequest extends LifecycleRequest
{
    protected function ability(): string
    {
        return 'archive';
    }

    protected function resource(): string
    {
        return 'team';
    }
}
