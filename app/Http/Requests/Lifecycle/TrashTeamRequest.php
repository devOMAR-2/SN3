<?php

namespace App\Http\Requests\Lifecycle;

/**
 * DELETE /api/v1/teams/{team}: move to the 30-day trash. Owner only.
 */
class TrashTeamRequest extends LifecycleRequest
{
    protected function ability(): string
    {
        return 'delete';
    }

    protected function resource(): string
    {
        return 'team';
    }
}
