<?php

namespace App\Models;

use Database\Factories\TaskChecklistItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A checklist item of one task (DATABASE.md §5, API_SPEC.md §6.2, CORE-005, ADR-023 §7). Reach items
 * only through a task the caller may see (`$task->checklistItems()`).
 *
 * `position` is the stored rank, kept dense (`0..n-1`) by the checklist actions, so it is also the
 * 0-based ordinal the API returns.
 *
 * @property string $id
 * @property string $task_id
 * @property string $content
 * @property bool $is_done
 * @property int $position
 * @property string|null $completed_by
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Task $task
 */
class TaskChecklistItem extends Model
{
    /** @use HasFactory<TaskChecklistItemFactory> */
    use HasFactory, HasUuids;

    /**
     * Everything is set by the checklist actions.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_done' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_done' => 'boolean',
            'position' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
