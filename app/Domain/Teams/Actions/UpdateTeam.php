<?php

namespace App\Domain\Teams\Actions;

use App\Models\Team;
use App\Support\Exceptions\DomainConflictException;
use App\Support\Exceptions\ResourceLockedException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

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
        if (array_key_exists('description', $changes) && $changes['description'] === '') {
            $changes['description'] = null;
        }

        return DB::transaction(function () use ($team, $changes): Team {
            // Lock the row so an archive (CORE-010) cannot commit between the check and the write.
            $locked = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();

            if ($locked->isArchived()) {
                throw new ResourceLockedException('Archived teams are read-only.');
            }

            $locked->fill(Arr::only($changes, ['name', 'slug']));

            if (array_key_exists('description', $changes)) {
                $locked->description = $changes['description'];
            }

            if (! $locked->isDirty()) {
                return $locked;
            }

            if ($locked->isDirty('slug') && Team::withTrashed()->where('slug', $locked->slug)->whereKeyNot($locked->id)->exists()) {
                throw new DomainConflictException('Team slug is already taken.');
            }

            try {
                $locked->save();
            } catch (UniqueConstraintViolationException $e) {
                throw new DomainConflictException('Team slug is already taken.', previous: $e);
            }

            return $locked;
        });
    }
}
