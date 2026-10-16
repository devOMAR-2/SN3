<?php

namespace App\Http\Requests\Lifecycle;

/**
 * POST /api/v1/teams/{team}/restore-deleted: Owner only (the binding already hides the team from anyone else).
 */
class RestoreDeletedTeamRequest extends LifecycleRequest
{
    protected function ability(): string
    {
        return 'restore';
    }

    protected function resource(): string
    {
        return 'trashedTeam';
    }
}
