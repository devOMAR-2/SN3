<?php

// Shared helpers of the CORE-006 page tests (required by each file; not a test file itself).

use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;

function pageTeamMember(Team $team, TeamRole $role = TeamRole::Member): User
{
    return TeamMembership::factory()->for($team)->role($role)->create()->user;
}

function pageProjectMember(Project $project, User $user, ProjectRole $role = ProjectRole::Contributor): ProjectMembership
{
    return ProjectMembership::factory()->for($project)->member($user)->role($role)->create();
}

function pageProject(Team $team, string $visibility = 'team', array $attributes = []): Project
{
    $factory = Project::factory()->for($team)->withDefaultStatuses();

    return ($visibility === 'private' ? $factory->private() : $factory)->create($attributes);
}

/**
 * The actor of an access-matrix row: the Team Owner, a new member with `$role`, or another team's Owner.
 */
function pageActor(Team $team, string $role, Team $otherTeam): User
{
    return match ($role) {
        'owner' => $team->owner,
        'outsider' => $otherTeam->owner,
        default => pageTeamMember($team, TeamRole::from($role)),
    };
}

function pageProjectUrl(Team $team, Project $project, string $suffix = ''): string
{
    return "/t/{$team->slug}/p/{$project->project_key}{$suffix}";
}
