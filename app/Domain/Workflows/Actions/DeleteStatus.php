<?php

namespace App\Domain\Workflows\Actions;

use App\Domain\Projects\ProjectWriteLock;
use App\Domain\Workflows\StatusCategory;
use App\Domain\Workflows\StatusOrder;
use App\Domain\Workflows\StatusRules;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Support\Concurrency\StaleVersionException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Removes a status (API_SPEC.md §5 DELETE, UC-005, WF-01) against its `If-Match` version.
 *
 * In one transaction, under the team → project → status locks:
 *
 * 1. 423 / 404 / 409 from ProjectWriteLock, then 404 if the status is gone and 412 if stale.
 * 2. `replacement_status_id`, when given, must be another status of the same project (422
 *    `exists` / `different`; a status of another project is answered like a random ID).
 * 3. The project must keep a `todo` and a `done` status (409).
 * 4. The status is deleted. If it was the default, the replacement becomes the default, otherwise
 *    the first remaining `todo` status.
 * 5. The remaining ranks are rewritten densely, in their current order.
 *
 * Tasks do not exist yet. CORE-005 adds, at step 4: move every task of the status to the
 * replacement in this transaction, and refuse (409) when the status still has tasks and no
 * replacement was given. Its `(status_id, project_id)` foreign key must not cascade, so the
 * database refuses to delete a status still in use whatever the code does.
 */
final class DeleteStatus
{
    /**
     * @throws StaleVersionException
     */
    public function handle(Project $project, ProjectStatus $status, User $actor, int $expectedVersion, ?string $replacementId = null): void
    {
        DB::transaction(function () use ($project, $status, $actor, $expectedVersion, $replacementId): void {
            $lockedProject = ProjectWriteLock::project($project, $actor, 'update');

            $statuses = $lockedProject->statuses()->orderBy('position')->lock(ProjectStatus::WRITE_LOCK)->get();
            $locked = $statuses->firstWhere('id', $status->id)
                ?? throw (new ModelNotFoundException)->setModel(ProjectStatus::class, [$status->id]);

            if ($locked->version !== $expectedVersion) {
                throw new StaleVersionException;
            }

            $remaining = $statuses->reject(fn (ProjectStatus $other): bool => $other->id === $locked->id)->values();
            $replacement = null;

            if ($replacementId !== null) {
                if ($replacementId === $locked->id) {
                    throw ValidationException::withMessages(['replacement_status_id' => 'different']);
                }

                $replacement = $remaining->firstWhere('id', $replacementId)
                    ?? throw ValidationException::withMessages(['replacement_status_id' => 'exists']);
            }

            StatusRules::assertRequiredCategories($remaining);

            $locked->delete();

            if ($locked->is_default) {
                $default = $replacement
                    ?? $remaining->first(fn (ProjectStatus $other): bool => $other->category === StatusCategory::Todo);

                // assertRequiredCategories() guarantees a remaining `todo` status.
                $default?->forceFill(['is_default' => true])->save();
            }

            StatusOrder::write($lockedProject->id, $remaining->map(fn (ProjectStatus $other): string => $other->id)->all());

            Log::info('workflow.status_deleted', [
                'team_id' => $lockedProject->team_id,
                'project_id' => $lockedProject->id,
                'status_id' => $locked->id,
                'actor_id' => $actor->id,
                'replacement_status_id' => $replacement?->id,
            ]);
        });
    }
}
