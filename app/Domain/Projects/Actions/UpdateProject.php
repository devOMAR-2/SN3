<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\ProjectVisibility;
use App\Domain\Projects\ProjectWriteLock;
use App\Models\Project;
use App\Models\User;
use App\Support\Concurrency\OptimisticLock;
use App\Support\Concurrency\StaleVersionException;
use App\Support\Exceptions\DomainConflictException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Updates a project's name, key, description and visibility (API_SPEC.md §4 PATCH) against the
 * client's `If-Match` version (§1.4): 412 on a stale version, and `version` + 1 when something
 * changed.
 *
 * Metadata and visibility change in one versioned write, so a request never applies half of its
 * changes. A visibility change is audited (NFR-SEC-005). Access needs no further re-evaluation:
 * every read goes through AccessibleProjects, so the new visibility applies from the next query
 * (FR-PROJ-007; ADR-020 §4).
 *
 * The key is frozen once a task number has been issued (DATABASE.md §5, PROJ-01): changing it
 * then is a 409, checked on the locked row (a trigger refuses it in the database too). Sending the
 * current key is not a change.
 */
final class UpdateProject
{
    /**
     * @param  array{name?: string, key?: string, description?: string|null, visibility?: string}  $changes
     *
     * @throws StaleVersionException
     */
    public function handle(Project $project, User $actor, int $expectedVersion, array $changes): Project
    {
        try {
            return DB::transaction(function () use ($project, $actor, $expectedVersion, $changes): Project {
                $lockedTeam = ProjectWriteLock::team($project->team_id);
                $previous = $project->visibility;

                $updated = OptimisticLock::update($project, $expectedVersion, function (Project $locked) use ($changes, &$previous): void {
                    $previous = $locked->visibility;

                    $locked->fill(Arr::only($changes, ['name']));

                    if (array_key_exists('key', $changes)) {
                        if ($changes['key'] !== $locked->project_key && $locked->keyIsFrozen()) {
                            throw new DomainConflictException('The project key is frozen after the first task.');
                        }

                        $locked->project_key = $changes['key'];
                    }

                    if (array_key_exists('description', $changes)) {
                        $locked->description = $changes['description'] === '' ? null : $changes['description'];
                    }

                    if (array_key_exists('visibility', $changes)) {
                        $locked->visibility = ProjectVisibility::from($changes['visibility']);
                    }
                }, guard: function (Project $locked) use ($lockedTeam, $actor): void {
                    ProjectWriteLock::assertWritable($locked, $lockedTeam, $actor, 'update');
                }, lock: Project::WRITE_LOCK);

                if ($updated->visibility !== $previous) {
                    Log::info('project.visibility_changed', [
                        'team_id' => $updated->team_id,
                        'project_id' => $updated->id,
                        'actor_id' => $actor->id,
                        'from' => $previous->value,
                        'to' => $updated->visibility->value,
                    ]);
                }

                return $updated;
            });
        } catch (UniqueConstraintViolationException $e) {
            // A key used by another project of the team, trashed ones included: 409.
            if (! str_contains($e->getMessage(), 'projects_team_id_project_key_unique')) {
                throw $e;
            }

            // Keys are approved team-level metadata (ADR-020 §10), but a conflict may still be a probe
            // for a private project's key: log who hit it, never which project holds the key.
            Log::warning('project.key_conflict', ['team_id' => $project->team_id, 'actor_id' => $actor->id]);

            throw new DomainConflictException('Project key is already taken in this team.', previous: $e);
        }
    }
}
