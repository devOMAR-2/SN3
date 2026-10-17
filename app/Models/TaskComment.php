<?php

namespace App\Models;

use App\Domain\Trash\TrashRetention;
use Database\Factories\TaskCommentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A comment on one task (DATABASE.md §5, API_SPEC.md §6.2, CORE-008, ADR-027 §2). Reach comments only
 * through a task the caller may see (`$task->comments()` on a task resolved by AccessibleTasks).
 *
 * `body` is Markdown source, returned as written and sanitised when it is rendered. Mentions are the
 * users the server resolved from `@<user-id>` tokens in the body (CommentMentions). A deleted comment
 * (`deleted_at`) is invisible to every read path and leaves a tombstone in the activity log; it is
 * pruned for good after the 30-day recovery window (TrashRetention, `model:prune`).
 *
 * @property string $id
 * @property string $team_id
 * @property string $project_id
 * @property string $task_id
 * @property string $author_user_id
 * @property string $body
 * @property Carbon|null $edited_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Task $task
 * @property-read Collection<int, User> $mentions
 */
class TaskComment extends Model
{
    /** @use HasFactory<TaskCommentFactory> */
    use HasFactory, HasUuids, MassPrunable, SoftDeletes;

    /** Row lock of the comment writers, after the team, project and task locks. */
    public const WRITE_LOCK = 'for update';

    /**
     * Everything is set by the comment actions.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Users mentioned in the comment, resolved and validated by the server (CommentMentions).
     *
     * @return BelongsToMany<User, $this>
     */
    public function mentions(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_comment_mentions', 'comment_id', 'user_id');
    }

    /**
     * Deleted comments past the 30-day recovery window (DATABASE.md §5 "soft-deletion follows the
     * 30-day recovery policy"), removed by the daily `model:prune`.
     *
     * @return Builder<TaskComment>
     */
    public function prunable(): Builder
    {
        return static::onlyTrashed()->where('deleted_at', '<=', TrashRetention::cutoff());
    }
}
