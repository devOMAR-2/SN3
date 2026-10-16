<?php

namespace App\Domain\Workflows\Actions;

use App\Domain\Projects\ProjectWriteLock;
use App\Domain\Workflows\StatusCategory;
use App\Domain\Workflows\StatusRules;
use App\Domain\Workflows\WorkflowVersion;
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
 *
 * The default status stays a `todo` status (new tasks start in it): when it moves to another
 * category, the default passes to the first remaining `todo` status by position. A real change
 * increments the workflow version.
 */
final class UpdateStatus
{
    /**
     * @param  array{name?: string, color?: string|null, category?: string}  $changes
     * @return ProjectStatus with its locked `project` loaded, carrying the workflow version
     *
     * @throws StaleVersionException
     */
    public function handle(Project $project, ProjectStatus $status, User $actor, int $expectedVersion, array $changes): ProjectStatus
    {
        try {
            return DB::transaction(function () use ($project, $status, $actor, $expectedVersion, $changes): ProjectStatus {
                $lockedProject = ProjectWriteLock::project($project, $actor, 'manageWorkflow');
                $previous = $status->category;
                /** @var ProjectStatus|null $newDefault */
                $newDefault = null;

                $updated = OptimisticLock::update($status, $expectedVersion, function (ProjectStatus $locked) use ($lockedProject, $changes, &$previous, &$newDefault): void {
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

                    if ($locked->category === $previous) {
                        return;
                    }

                    $others = $lockedProject->statuses()->whereKeyNot($locked->id)->orderBy('position')->lock(ProjectStatus::WRITE_LOCK)->get();
                    StatusRules::assertRequiredCategories($others->concat([$locked]));

                    if ($locked->is_default && $locked->category !== StatusCategory::Todo) {
                        // assertRequiredCategories() guarantees another `todo` status.
                        $newDefault = $others->first(fn (ProjectStatus $other): bool => $other->category === StatusCategory::Todo);
                        $locked->is_default = false;
                    }
                }, lock: ProjectStatus::WRITE_LOCK);

                // After the old default was saved without the flag (one default per project).
                if ($newDefault instanceof ProjectStatus) {
                    $newDefault->forceFill(['is_default' => true])->save();
                }

                if ($updated->wasChanged()) {
                    WorkflowVersion::bump($lockedProject);
                }

                if ($updated->category !== $previous) {
                    Log::info('workflow.status_recategorised', [
                        'team_id' => $lockedProject->team_id,
                        'project_id' => $lockedProject->id,
                        'status_id' => $updated->id,
                        'actor_id' => $actor->id,
                        'from' => $previous->value,
                        'to' => $updated->category->value,
                        'default_status_id' => $newDefault?->id,
                    ]);
                }

                return $updated->setRelation('project', $lockedProject);
            });
        } catch (UniqueConstraintViolationException $e) {
            throw StatusRules::duplicateName($e);
        }
    }
}
