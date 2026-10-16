<?php

// Shared helpers of the CORE-005 task tests (required by each file; not a test file itself).

use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Task;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Support\Str;

function taskTeamMember(Team $team, TeamRole $role = TeamRole::Member): User
{
    return TeamMembership::factory()->for($team)->role($role)->create()->user;
}

function taskProjectMember(Project $project, User $user, ProjectRole $role = ProjectRole::Contributor): ProjectMembership
{
    return ProjectMembership::factory()->for($project)->member($user)->role($role)->create();
}

function taskProject(Team $team, string $visibility = 'team', array $attributes = []): Project
{
    $factory = Project::factory()->for($team)->withDefaultStatuses();

    return ($visibility === 'private' ? $factory->private() : $factory)->create($attributes);
}

function taskIn(Project $project, array $attributes = []): Task
{
    return Task::factory()->for($project)->create($attributes);
}

/**
 * @return array<string, string>
 */
function taskIdempotencyHeader(?string $key = null): array
{
    return ['Idempotency-Key' => $key ?? (string) Str::uuid()];
}

/**
 * @return array<string, string>
 */
function taskIfMatch(int|Task $version): array
{
    return ['If-Match' => '"'.($version instanceof Task ? $version->fresh()?->version : $version).'"'];
}

function taskDefaultStatusId(Project $project): string
{
    return $project->statuses()->where('is_default', true)->firstOrFail()->id;
}

function taskStatusId(Project $project, string $category): string
{
    return $project->statuses()->where('category', $category)->orderBy('position')->firstOrFail()->id;
}
