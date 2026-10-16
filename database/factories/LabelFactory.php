<?php

namespace Database\Factories;

use App\Models\Label;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Labels of a project: `Label::factory()->for($project)->create()`.
 *
 * @extends Factory<Label>
 */
class LabelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'team_id' => function (array $attributes): string {
                $projectId = $attributes['project_id'] ?? null;

                return Project::query()->withTrashed()->findOrFail(is_string($projectId) ? $projectId : '')->team_id;
            },
            'name' => 'Label '.Str::upper(Str::random(8)),
            'color' => null,
        ];
    }
}
