<?php

namespace Database\Factories;

use App\Domain\Projects\ProjectVisibility;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Projects created by their team's Owner by default, without a membership row (the Owner needs
 * none). Use ProjectMembership::factory() for explicit members.
 *
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->catchPhrase(),
            'project_key' => 'P'.Str::upper(Str::random(6)),
            'visibility' => ProjectVisibility::Team,
            'created_by' => fn (array $attributes): string => self::teamOwner($attributes),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function teamOwner(array $attributes): string
    {
        $teamId = $attributes['team_id'] ?? null;

        return Team::query()->findOrFail(is_string($teamId) ? $teamId : '')->owner_user_id;
    }

    public function private(): static
    {
        return $this->state(['visibility' => ProjectVisibility::Private]);
    }

    public function archived(): static
    {
        return $this->state(['archived_at' => now()]);
    }
}
