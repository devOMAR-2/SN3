<?php

namespace Database\Factories;

use App\Domain\Projects\TaskNumbers;
use App\Domain\Tasks\TaskOrder;
use App\Domain\Tasks\TaskPriority;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Tasks of an existing project that has statuses (`Project::factory()->withDefaultStatuses()`):
 * `Task::factory()->for($project)->create()`. Like CreateTask, the factory takes the next task number
 * from TaskNumbers, starts the task in the project's default status, appends it to that column and
 * makes the team's Owner its creator.
 *
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory()->withDefaultStatuses(),
            'team_id' => fn (array $attributes): string => self::project($attributes)->team_id,
            'task_number' => fn (array $attributes): int => DB::transaction(fn (): int => TaskNumbers::allocate(self::project($attributes))),
            'title' => rtrim(fake()->sentence(4), '.'),
            'status_id' => fn (array $attributes): string => self::project($attributes)->statuses()->where('is_default', true)->firstOrFail()->id,
            'created_by' => fn (array $attributes): string => self::project($attributes)->team->owner_user_id,
            'priority' => TaskPriority::None,
            'position' => fn (array $attributes): string => TaskOrder::next(self::project($attributes)->id, self::string($attributes, 'status_id')),
        ];
    }

    public function assignedTo(User $user): static
    {
        return $this->state(['assignee_user_id' => $user->id]);
    }

    public function childOf(Task $parent): static
    {
        return $this->state(['project_id' => $parent->project_id, 'parent_task_id' => $parent->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function project(array $attributes): Project
    {
        return Project::query()->withTrashed()->findOrFail(self::string($attributes, 'project_id'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function string(array $attributes, string $key): string
    {
        $value = $attributes[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
