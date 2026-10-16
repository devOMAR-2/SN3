<?php

namespace App\Models;

use Database\Factories\LabelFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A project label (DATABASE.md §5, API_SPEC.md §5, CORE-005, ADR-023 §6). Reach labels only through
 * a project the caller may see, e.g. `$project->labels()`. Names are unique per project ignoring
 * case; tasks carry labels of their own project only (`task_labels` composite keys).
 *
 * @property string $id
 * @property string $team_id
 * @property string $project_id
 * @property string $name
 * @property string|null $color
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 */
class Label extends Model
{
    /** @use HasFactory<LabelFactory> */
    use HasFactory, HasUuids;

    /**
     * Project and team are set by the label actions.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'color'];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
