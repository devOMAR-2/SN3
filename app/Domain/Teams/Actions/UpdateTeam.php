<?php

namespace App\Domain\Teams\Actions;

use App\Models\Team;
use App\Support\Exceptions\DomainConflictException;
use App\Support\Exceptions\ResourceLockedException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;

/**
 * Updates a team's name, slug and description (API_SPEC.md §3). Archived teams are read-only (423);
 * a slug that another team holds is a 409 conflict.
 */
final class UpdateTeam
{
    /**
     * @param  array{name?: string, slug?: string, description?: string|null}  $changes
     */
    public function handle(Team $team, array $changes): Team
    {
        if ($team->isArchived()) {
            throw new ResourceLockedException('Archived teams are read-only.');
        }

        if (array_key_exists('description', $changes) && $changes['description'] === '') {
            $changes['description'] = null;
        }

        $team->fill(Arr::only($changes, ['name', 'slug']));

        if (array_key_exists('description', $changes)) {
            $team->description = $changes['description'];
        }

        if (! $team->isDirty()) {
            return $team;
        }

        if ($team->isDirty('slug') && Team::withTrashed()->where('slug', $team->slug)->whereKeyNot($team->id)->exists()) {
            throw new DomainConflictException('Team slug is already taken.');
        }

        try {
            $team->save();
        } catch (UniqueConstraintViolationException $e) {
            throw new DomainConflictException('Team slug is already taken.', previous: $e);
        }

        return $team;
    }
}
