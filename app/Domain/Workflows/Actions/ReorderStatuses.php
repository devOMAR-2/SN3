<?php

namespace App\Domain\Workflows\Actions;

use App\Domain\Projects\ProjectWriteLock;
use App\Domain\Workflows\StatusOrder;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Replaces the board order of a project's statuses (API_SPEC.md §5 `PUT /statuses/order`,
 * FR-CUST-001). The body is the full ordered list of the project's status IDs.
 *
 * Under the team → project locks the list must be exactly the project's current statuses: a status
 * added or removed since the client loaded the list makes it a 409 (reload and retry), so a reorder
 * can never drop or resurrect a status. Ranks become `0..n-1` in the given order. Status versions
 * are not bumped (ADR-021 §3): the position is not part of a status PATCH, so a rename racing a
 * reorder loses nothing.
 */
final class ReorderStatuses
{
    /**
     * @param  list<string>  $orderedIds  lower-case UUIDs, distinct
     * @return Collection<int, ProjectStatus> the statuses in their new order
     */
    public function handle(Project $project, User $actor, array $orderedIds): Collection
    {
        return DB::transaction(function () use ($project, $actor, $orderedIds): Collection {
            $lockedProject = ProjectWriteLock::project($project, $actor, 'update');
            $current = $lockedProject->statuses()->pluck('id')->all();

            $given = $orderedIds;
            sort($current);
            sort($given);

            if ($current !== $given) {
                throw new DomainConflictException('The ordered list does not match the project\'s statuses.');
            }

            StatusOrder::write($lockedProject->id, $orderedIds);

            Log::info('workflow.statuses_reordered', [
                'team_id' => $lockedProject->team_id,
                'project_id' => $lockedProject->id,
                'actor_id' => $actor->id,
            ]);

            return $lockedProject->statuses()->orderBy('position')->get();
        });
    }
}
