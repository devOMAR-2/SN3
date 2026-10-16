<?php

namespace App\Models;

use App\Domain\Projects\ProjectRole;
use Database\Factories\ProjectMembershipFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An explicit project role (DATABASE.md §4). Unique per (project, user). The database requires the
 * user to be a member of the project's own team, through composite foreign keys.
 *
 * @property string $id
 * @property string $project_id
 * @property string $team_id
 * @property string $user_id
 * @property ProjectRole $project_role
 * @property string $added_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read User $user
 */
class ProjectMembership extends Model
{
    /** @use HasFactory<ProjectMembershipFactory> */
    use HasFactory, HasUuids;

    /**
     * Created by the project actions only, never from request payloads.
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
            'project_role' => ProjectRole::class,
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
