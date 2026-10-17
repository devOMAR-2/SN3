<?php

namespace App\Models;

use App\Domain\Projects\TaskNumbers;
use App\Domain\Tasks\TaskPriority;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A task of exactly one project (DATABASE.md §5, CORE-005, ADR-023). Reach tasks only through a
 * project the caller may see: the `{task}` binding (AccessibleTasks) or `$project->tasks()` on a
 * project resolved by AccessibleProjects. A task in a hidden project is a 404 everywhere.
 *
 * Soft-deleted tasks are in the task trash (API_SPEC.md §6.1 DELETE / restore). `task_number` is
 * issued by TaskNumbers::allocate() and never changes; the readable key is `{project_key}-{n}`.
 *
 * @property string $id
 * @property string $team_id
 * @property string $project_id
 * @property int $task_number
 * @property string $title
 * @property string|null $description
 * @property string $status_id
 * @property string|null $assignee_user_id
 * @property string $created_by
 * @property TaskPriority $priority
 * @property Carbon|null $due_date
 * @property string $position
 * @property string|null $parent_task_id
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Project $project
 * @property-read ProjectStatus $status
 * @property-read Collection<int, Label> $labels
 * @property-read Collection<int, TaskChecklistItem> $checklistItems
 * @property-read Collection<int, TaskComment> $comments
 * @property-read Collection<int, TaskAttachment> $attachments
 */
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Row lock the task writers take, after the team and project locks (ProjectWriteLock). NO KEY
     * UPDATE does not block the KEY SHARE lock that inserting a label link or a checklist item takes.
     */
    public const WRITE_LOCK = 'for no key update';

    /**
     * Everything is set by the task actions.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * Generated sort keys (`priority_rank`, `due_sort`) stay out of every representation.
     *
     * @var list<string>
     */
    protected $hidden = ['priority_rank', 'due_sort'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'priority' => 'none',
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'task_number' => 'integer',
            'priority' => TaskPriority::class,
            'due_date' => 'date',
            'version' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<ProjectStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(ProjectStatus::class, 'status_id');
    }

    /**
     * @return BelongsToMany<Label, $this>
     */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'task_labels');
    }

    /**
     * Checklist items in their order (`position`, then `id`).
     *
     * @return HasMany<TaskChecklistItem, $this>
     */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class);
    }

    /**
     * Comments (CORE-008); live ones only unless `withTrashed()`.
     *
     * @return HasMany<TaskComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    /**
     * Attachments (CORE-008), including those being removed; use `->live()` for the visible ones.
     *
     * @return HasMany<TaskAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class);
    }

    /**
     * Direct subtasks, live ones only.
     *
     * @return HasMany<Task, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    /**
     * The readable key, `WEB-104` (FR-TASK-003). The project key is frozen once a task number has
     * been issued (ADR-021 §5), so this never changes for a task that exists.
     */
    public function key(Project $project): string
    {
        return TaskNumbers::key($project, $this->task_number);
    }
}
