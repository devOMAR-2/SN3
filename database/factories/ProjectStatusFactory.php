<?php

namespace Database\Factories;

use App\Domain\Workflows\StatusCategory;
use App\Models\Project;
use App\Models\ProjectStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Statuses appended after the project's existing ones:
 * `ProjectStatus::factory()->for($project)->create()`. Projects made with ProjectFactory have no
 * statuses; use `Project::factory()->withDefaultStatuses()` for the default workflow.
 *
 * @extends Factory<ProjectStatus>
 */
class ProjectStatusFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'team_id' => fn (array $attributes): string => self::project($attributes)->team_id,
            'name' => 'Status '.Str::upper(Str::random(8)),
            'category' => StatusCategory::InProgress,
            'position' => fn (array $attributes): int => ProjectStatus::query()->where('project_id', self::project($attributes)->id)->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function project(array $attributes): Project
    {
        $projectId = $attributes['project_id'] ?? null;

        return Project::query()->withTrashed()->findOrFail(is_string($projectId) ? $projectId : '');
    }

    public function category(StatusCategory $category): static
    {
        return $this->state(['category' => $category]);
    }
}
