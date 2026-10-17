<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One append-only activity entry (DATABASE.md §5 `task_activities`, API_SPEC.md §9, CORE-008,
 * ADR-027 §3): who did what, when, to which team, project or task.
 *
 * - Task entries have `task_id` (and `project_id`); project entries have `project_id` only; team
 *   entries have neither. Entries are written by App\Domain\Activity\Activity, never updated or
 *   deleted (a trigger refuses it); the trash purge removes them with their team, project or task.
 * - `changes` is a bounded allowlist of IDs, enum values, dates and field names. It never holds
 *   titles, descriptions, comment bodies, file names or other free text.
 * - Read only through App\Domain\Activity\ActivityFeed, which applies AccessibleProjects.
 *
 * @property string $id
 * @property string $team_id
 * @property string|null $project_id
 * @property string|null $task_id
 * @property string|null $actor_user_id
 * @property string $action
 * @property array<string, mixed> $changes
 * @property string $source
 * @property Carbon|null $created_at
 * @property-read Team $team
 */
class TaskActivity extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * Everything is set by App\Domain\Activity\Activity.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'changes' => '{}',
        'source' => 'user',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
