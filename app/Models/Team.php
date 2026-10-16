<?php

namespace App\Models;

use App\Domain\Teams\TeamRole;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A tenant (DATABASE.md §3). Resolve teams for a user only through App\Support\Tenancy\AccessibleTeams.
 *
 * `owner_user_id` always matches the single `owner` membership; the database enforces this at
 * commit (see the create_teams_tables migration). Soft-deleted teams sit in the 30-day trash.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $owner_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $archived_at
 * @property Carbon|null $deleted_at
 * @property-read User $owner
 * @property-read Collection<int, TeamMembership> $memberships
 * @property-read Collection<int, Project> $projects
 */
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Row lock every team writer takes first (ADR-018 §4). NO KEY UPDATE serialises the writers
     * like FOR UPDATE but does not block the KEY SHARE lock that inserting a membership takes for
     * its foreign key (invitation acceptance, CORE-002).
     */
    public const WRITE_LOCK = 'for no key update';

    /**
     * Ownership changes only through a transactional transfer, never mass assignment.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return HasMany<TeamMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TeamMembership::class);
    }

    /**
     * Every project of the team, private ones included. Never list these for a user directly; go
     * through App\Support\Tenancy\AccessibleProjects.
     *
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * The user's role in this team, or null when they are not a member.
     *
     * AccessibleTeams eager-loads the viewer's own membership (possibly only that one), so policy
     * checks on a resolved team cost no extra query; anyone else is looked up.
     */
    public function roleOf(User $user): ?TeamRole
    {
        if ($this->relationLoaded('memberships')) {
            $loaded = $this->memberships->firstWhere('user_id', $user->id);

            if ($loaded !== null) {
                return $loaded->role;
            }
        }

        return $this->memberships()->where('user_id', $user->id)->first()?->role;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
