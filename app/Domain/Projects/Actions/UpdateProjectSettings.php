<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\ProjectWriteLock;
use App\Models\Project;
use App\Models\User;
use App\Support\Concurrency\OptimisticLock;
use App\Support\Concurrency\StaleVersionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Changes a project's configuration (API_SPEC.md §5 `PATCH /projects/{project}/settings`; ADR-020
 * Consequences, ADR-021 §6). Settings are part of the Project resource, so the write is checked
 * against `projects.version` (`If-Match`) and increments it, like `PATCH /projects/{project}`.
 *
 * Only `sprints_enabled` exists so far (DATABASE.md §4; default off, FR-SPR-001). Disabling it keeps
 * sprint history; FLEX-005 adds the refusal while a sprint is active (SPR-02).
 */
final class UpdateProjectSettings
{
    /**
     * @param  array{sprints_enabled?: bool}  $changes
     *
     * @throws StaleVersionException
     */
    public function handle(Project $project, User $actor, int $expectedVersion, array $changes): Project
    {
        return DB::transaction(function () use ($project, $actor, $expectedVersion, $changes): Project {
            $lockedTeam = ProjectWriteLock::team($project->team_id);
            $previous = $project->sprints_enabled;

            $updated = OptimisticLock::update($project, $expectedVersion, function (Project $locked) use ($changes, &$previous): void {
                $previous = $locked->sprints_enabled;

                if (array_key_exists('sprints_enabled', $changes)) {
                    $locked->sprints_enabled = $changes['sprints_enabled'];
                }
            }, guard: function (Project $locked) use ($lockedTeam, $actor): void {
                ProjectWriteLock::assertWritable($locked, $lockedTeam, $actor, 'update');
            }, lock: Project::WRITE_LOCK);

            if ($updated->sprints_enabled !== $previous) {
                Log::info('project.settings_changed', [
                    'team_id' => $updated->team_id,
                    'project_id' => $updated->id,
                    'actor_id' => $actor->id,
                    'sprints_enabled' => $updated->sprints_enabled,
                ]);
            }

            return $updated;
        });
    }
}
