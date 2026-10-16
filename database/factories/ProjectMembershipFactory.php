<?php

namespace Database\Factories;

use App\Domain\Projects\ProjectRole;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Explicit project memberships. The user must already be a member of the project's team (the
 * database enforces it): `ProjectMembership::factory()->for($project)->member($user)->create()`.
 *
 * @extends Factory<ProjectMembership>
 */
class ProjectMembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'team_id' => fn (array $attributes): string => self::project($attributes)->team_id,
            'project_role' => ProjectRole::Contributor,
            'added_by' => fn (array $attributes): string => self::project($attributes)->created_by,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function project(array $attributes): Project
    {
        $projectId = $attributes['project_id'] ?? null;

        return Project::query()->findOrFail(is_string($projectId) ? $projectId : '');
    }

    public function member(User $user): static
    {
        return $this->state(['user_id' => $user->id]);
    }

    public function role(ProjectRole $role): static
    {
        return $this->state(['project_role' => $role]);
    }
}
