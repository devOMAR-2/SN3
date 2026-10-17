<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Comments on an existing task: `TaskComment::factory()->for($task)->create()`. The comment takes the
 * task's project and team; the team's Owner is its author unless `by($user)`. Mentions are not
 * resolved here (CreateComment does that).
 *
 * @extends Factory<TaskComment>
 */
class TaskCommentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'project_id' => fn (array $attributes): string => self::task($attributes)->project_id,
            'team_id' => fn (array $attributes): string => self::task($attributes)->team_id,
            'author_user_id' => fn (array $attributes): string => self::task($attributes)->project->team->owner_user_id,
            'body' => fake()->sentence(8),
        ];
    }

    public function by(User $user): static
    {
        return $this->state(['author_user_id' => $user->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function task(array $attributes): Task
    {
        $id = $attributes['task_id'] ?? null;

        return Task::withTrashed()->with('project.team')->findOrFail(is_string($id) ? $id : '');
    }
}
