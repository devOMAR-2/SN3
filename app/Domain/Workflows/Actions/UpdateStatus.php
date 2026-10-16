<?php

namespace App\Domain\Workflows\Actions;

use App\Domain\Projects\ProjectWriteLock;
use App\Domain\Workflows\StatusCategory;
use App\Domain\Workflows\StatusRules;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Support\Concurrency\OptimisticLock;
use App\Support\Concurrency\StaleVersionException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Renames, recolours or recategorises a status (API_SPEC.md §5 PATCH) against its `If-Match`
 * version (§1.4, §18 `project_statuses.version`). Its position changes only through the reorder
 * endpoint.
 *
 * Locks team → project → status. 423 (archived) and the actor's access are checked before the
 * version (423 wins over 412); a status removed meanwhile is a 404. Moving the last `todo` or
 * `done` status to another category is 409, and so is a name already used in the project.
 */
final class UpdateStatus
{
    /**
     * @param  array{name?: string, color?: string|null, category?: string}  $changes
     *
     * @throws StaleVersionException
     */
    public function handle(Project $project, ProjectStatus $status, User $actor, int $expectedVersion, array $changes): ProjectStatus
    {
        try {
            return DB::transaction(function () use ($project, $status, $actor, $expectedVersion, $changes): ProjectStatus {
                $lockedProject = ProjectWriteLock::project($project, $actor, 'update');
                $previous = $status->category;

                $updated = OptimisticLock::update($status, $expectedVersion, function (ProjectStatus $locked) use ($lockedProject, $changes, &$previous): void {
                    $previous = $locked->category;

                    if (array_key_exists('name', $changes)) {
                        $locked->name = $changes['name'];
                    }

                    if (array_key_exists('color', $changes)) {
                        $locked->color = $changes['color'];
                    }

                    if (array_key_exists('category', $changes)) {
                        $locked->category = StatusCategory::from($changes['category']);
                    }

                    if ($locked->category !== $previous) {
                        $others = $lockedProject->statuses()->whereKeyNot($locked->id)->get();
                        StatusRules::assertRequiredCategories($others->push($locked));
                    }
                }, lock: ProjectStatus::WRITE_LOCK);

                if ($updated->category !== $previous) {
                    Log::info('workflow.status_recategorised', [
                        'team_id' => $lockedProject->team_id,
                        'project_id' => $lockedProject->id,
                        'status_id' => $updated->id,
                        'actor_id' => $actor->id,
                        'from' => $previous->value,
                        'to' => $updated->category->value,
                    ]);
                }

                return $updated;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw StatusRules::duplicateName($e);
        }
    }
}
