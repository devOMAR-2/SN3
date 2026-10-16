<?php

namespace App\Models;

use App\Domain\Workflows\StatusCategory;
use Database\Factories\ProjectStatusFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A workflow status of one project (DATABASE.md §5, CORE-004, ADR-021). Reach statuses only
 * through a project the caller may see (the `{project}` binding or AccessibleProjects), e.g.
 * `$project->statuses()`.
 *
 * `position` is the stored rank; the workflow actions keep it dense (`0..n-1`), so it is also the
 * 0-based ordinal the API returns.
 *
 * @property string $id
 * @property string $project_id
 * @property string $team_id
 * @property string $name
 * @property StatusCategory $category
 * @property int $position
 * @property string|null $color
 * @property bool $is_default
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 */
class ProjectStatus extends Model
{
    /** @use HasFactory<ProjectStatusFactory> */
    use HasFactory, HasUuids;

    /**
     * Row lock the status writers take, after the team and project locks. NO KEY UPDATE does not
     * block the KEY SHARE lock a task insert takes for its `(status_id, project_id)` key (CORE-005).
     */
    public const WRITE_LOCK = 'for no key update';

    /**
     * Project, team, position, default flag and version are set by the workflow actions only.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'category',
        'color',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_default' => false,
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => StatusCategory::class,
            'position' => 'integer',
            'is_default' => 'boolean',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
