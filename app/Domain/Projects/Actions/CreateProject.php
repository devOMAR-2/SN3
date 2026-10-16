<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\ProjectRole;
use App\Domain\Projects\ProjectVisibility;
use App\Domain\Projects\ProjectWriteLock;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Team;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Creates a project (FR-PROJ-001/002, API_SPEC.md §4). Team-visible unless `private` is chosen
 * (DEC-017). The creator becomes its `manager` (DECIDED-003) in the same transaction.
 *
 * The team row is locked first, and the archived flag (423) and the creator's right to create
 * projects are re-checked on it. A key already used in the team, by a trashed project too, is a 409.
 */
final class CreateProject
{
    public function handle(
        Team $team,
        User $creator,
        string $name,
        string $key,
        ?string $description = null,
        ProjectVisibility $visibility = ProjectVisibility::Team,
    ): Project {
        try {
            return DB::transaction(function () use ($team, $creator, $name, $key, $description, $visibility): Project {
                $lockedTeam = ProjectWriteLock::team($team->id);

                if (! Gate::forUser($creator)->allows('createProject', $lockedTeam)) {
                    throw new DomainConflictException('Team role changed while the request waited; reload and try again.');
                }

                $project = new Project;
                $project->forceFill([
                    'team_id' => $team->id,
                    'name' => $name,
                    'project_key' => $key,
                    'description' => $description === '' ? null : $description,
                    'visibility' => $visibility,
                    'created_by' => $creator->id,
                ])->save();

                $membership = new ProjectMembership;
                $membership->forceFill([
                    'project_id' => $project->id,
                    'team_id' => $team->id,
                    'user_id' => $creator->id,
                    'project_role' => ProjectRole::Manager,
                    'added_by' => $creator->id,
                ])->save();

                Log::info('project.created', [
                    'team_id' => $team->id,
                    'project_id' => $project->id,
                    'actor_id' => $creator->id,
                    'visibility' => $visibility->value,
                ]);

                return $project;
            });
        } catch (UniqueConstraintViolationException $e) {
            // The unique index covers trashed projects too and also settles concurrent creates.
            if (! str_contains($e->getMessage(), 'projects_team_id_project_key_unique')) {
                throw $e;
            }

            // Keys are approved team-level metadata (ADR-020 §10), but a conflict may still be a probe
            // for a private project's key: log who hit it, never which project holds the key.
            Log::warning('project.key_conflict', ['team_id' => $team->id, 'actor_id' => $creator->id]);

            throw new DomainConflictException('Project key is already taken in this team.', previous: $e);
        }
    }
}
