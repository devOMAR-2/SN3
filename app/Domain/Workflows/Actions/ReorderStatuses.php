<?php

namespace App\Domain\Workflows\Actions;

use App\Domain\Projects\ProjectWriteLock;
use App\Domain\Workflows\StatusOrder;
use App\Domain\Workflows\WorkflowVersion;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Support\Concurrency\StaleVersionException;
use App\Support\Exceptions\DomainConflictException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Replaces the board order of a project's statuses (API_SPEC.md §5 `PUT /statuses/order`,
 * FR-CUST-001). The body is the full ordered list of the project's status IDs; `If-Match` carries
 * the workflow version the client's list came from (§1.4 "workflow configuration").
 *
 * Under the team → project locks: 423 / 404 / 409 from ProjectWriteLock first, then 412 when the
 * workflow changed since the client read it (another reorder, or a status created, edited or
 * deleted), so a reorder made from a stale list never silently overwrites a newer one. The list
 * must then be exactly the project's current statuses (409 otherwise). Ranks become `0..n-1` in
 * the given order and the workflow version is incremented when the order changed. Status versions
 * are not bumped (ADR-021 §3): the position is not part of a status PATCH.
 */
final class ReorderStatuses
{
    /**
     * @param  list<string>  $orderedIds  lower-case UUIDs, distinct
     * @return Collection<int, ProjectStatus> the statuses in their new order, each with the locked
     *                                        `project` loaded (carrying the workflow version)
     *
     * @throws StaleVersionException
     */
    public function handle(Project $project, User $actor, int $expectedWorkflowVersion, array $orderedIds): Collection
    {
        return DB::transaction(function () use ($project, $actor, $expectedWorkflowVersion, $orderedIds): Collection {
            $lockedProject = ProjectWriteLock::project($project, $actor, 'manageWorkflow');
            WorkflowVersion::assertCurrent($lockedProject, $expectedWorkflowVersion);

            $current = $lockedProject->statuses()->pluck('id')->all();

            $given = $orderedIds;
            sort($current);
            sort($given);

            if ($current !== $given) {
                throw new DomainConflictException('The ordered list does not match the project\'s statuses.');
            }

            if (StatusOrder::write($lockedProject->id, $orderedIds) > 0) {
                WorkflowVersion::bump($lockedProject);
            }

            Log::info('workflow.statuses_reordered', [
                'team_id' => $lockedProject->team_id,
                'project_id' => $lockedProject->id,
                'actor_id' => $actor->id,
            ]);

            $statuses = $lockedProject->statuses()->orderBy('position')->get();
            $statuses->each(fn (ProjectStatus $status) => $status->setRelation('project', $lockedProject));

            return $statuses;
        });
    }
}
