<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\TaskChecklistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Checklist items appended to a task's checklist: `TaskChecklistItem::factory()->for($task)->create()`.
 *
 * @extends Factory<TaskChecklistItem>
 */
class TaskChecklistItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'content' => rtrim(fake()->sentence(3), '.'),
            'position' => function (array $attributes): int {
                $taskId = $attributes['task_id'] ?? null;

                return TaskChecklistItem::query()->where('task_id', is_string($taskId) ? $taskId : '')->count();
            },
        ];
    }
}
