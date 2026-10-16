<?php

namespace App\Models;

use App\Domain\Projects\ProjectRole;
use App\Domain\Projects\ProjectVisibility;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A project in exactly one team (DATABASE.md §4). Resolve projects for a user only through
 * App\Support\Tenancy\AccessibleProjects, which applies team membership and private visibility.
 *
 * Soft-deleted projects sit in the 30-day trash (CORE-010); `archived_at` is the independent
 * read-only state.
 *
 * @property string $id
 * @property string $team_id
 * @property string $name
 * @property string $project_key
 * @property string|null $description
 * @property ProjectVisibility $visibility
 * @property string $created_by
 * @property bool $sprints_enabled
 * @property int $next_task_number
 * @property int $version
 * @property int $workflow_version
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Team $team
 * @property-read User $creator
 * @property-read Collection<int, ProjectMembership> $memberships
 * @property-read Collection<int, ProjectStatus> $statuses
 * @property-read Collection<int, Task> $tasks
 * @property-read Collection<int, Label> $labels
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Row lock every project writer takes, always after the team row lock (ADR-018 §4, ADR-020).
     * NO KEY UPDATE serialises writers without blocking the KEY SHARE lock that inserting a child
     * row (a membership, later a task) takes for its foreign key.
     */
    public const WRITE_LOCK = 'for no key update';

    /**
     * The user whose own project memberships the `memberships` relation holds, when it was loaded
     * filtered to that user (AccessibleProjects). Not a database column.
     */
    public ?string $membershipsLoadedFor = null;

    /**
     * Team, creator, counters and version are set by the project actions only.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'project_key',
        'description',
        'visibility',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'visibility' => 'team',
        'sprints_enabled' => false,
        'next_task_number' => 1,
        'version' => 1,
        'workflow_version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => ProjectVisibility::class,
            'sprints_enabled' => 'boolean',
            'next_task_number' => 'integer',
            'version' => 'integer',
            'workflow_version' => 'integer',
            'archived_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Explicit project memberships. The Team Owner needs none.
     *
     * @return HasMany<ProjectMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMembership::class);
    }

    /**
     * The user's explicit project role, or null without a membership row.
     *
     * AccessibleProjects eager-loads the viewer's own membership (possibly only that one), so
     * policy checks on a resolved project cost no extra query; anyone else is looked up.
     */
    public function roleOf(User $user): ?ProjectRole
    {
        if ($this->relationLoaded('memberships')) {
            $loaded = $this->memberships->firstWhere('user_id', $user->id);

            // When AccessibleProjects loaded the set for this user, a miss means "no membership"
            // without another query (no N+1 on project lists).
            if ($loaded !== null || $this->membershipsLoadedFor === $user->id) {
                return $loaded?->project_role;
            }
        }

        return $this->memberships()->where('user_id', $user->id)->first()?->project_role;
    }

    /**
     * Workflow statuses (CORE-004). Board order is `position`.
     *
     * @return HasMany<ProjectStatus, $this>
     */
    public function statuses(): HasMany
    {
        return $this->hasMany(ProjectStatus::class);
    }

    /**
     * Tasks (CORE-005); live ones only unless `withTrashed()`.
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Project labels (CORE-005).
     *
     * @return HasMany<Label, $this>
     */
    public function labels(): HasMany
    {
        return $this->hasMany(Label::class);
    }

    /**
     * True once a task number has been issued: the project key is then frozen (DATABASE.md §5,
     * PROJ-01; enforced again by a trigger), whether or not the task still exists.
     */
    public function keyIsFrozen(): bool
    {
        return $this->next_task_number > 1;
    }

    public function isPrivate(): bool
    {
        return $this->visibility === ProjectVisibility::Private;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
