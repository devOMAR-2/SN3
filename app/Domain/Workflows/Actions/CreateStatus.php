<?php

namespace App\Domain\Workflows\Actions;

use App\Domain\Projects\ProjectWriteLock;
use App\Domain\Workflows\StatusCategory;
use App\Domain\Workflows\StatusRules;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Adds a status at the end of the project's workflow (API_SPEC.md §5 POST, FR-CUST-001).
 *
 * Locks team → project (ProjectWriteLock: 423 when archived, 404/409 when the actor lost access
 * meanwhile), so the new rank `n` is computed while no other status writer can run. A name already
 * used in the project (ignoring case) and a full workflow (StatusRules::MAX_STATUSES) are 409.
 */
final class CreateStatus
{
    public function handle(Project $project, User $actor, string $name, StatusCategory $category, ?string $color = null): ProjectStatus
    {
        try {
            return DB::transaction(function () use ($project, $actor, $name, $category, $color): ProjectStatus {
                $locked = ProjectWriteLock::project($project, $actor, 'update');
                $count = $locked->statuses()->count();

                if ($count >= StatusRules::MAX_STATUSES) {
                    throw new DomainConflictException('The project has the maximum number of statuses.');
                }

                $status = new ProjectStatus;
                $status->forceFill([
                    'project_id' => $locked->id,
                    'team_id' => $locked->team_id,
                    'name' => $name,
                    'category' => $category,
                    'color' => $color,
                    'position' => $count,
                    'is_default' => false,
                ])->save();

                Log::info('workflow.status_created', [
                    'team_id' => $locked->team_id,
                    'project_id' => $locked->id,
                    'status_id' => $status->id,
                    'actor_id' => $actor->id,
                    'category' => $category->value,
                ]);

                return $status->refresh();
            });
        } catch (UniqueConstraintViolationException $e) {
            throw StatusRules::duplicateName($e);
        }
    }
}
