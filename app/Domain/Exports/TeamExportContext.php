<?php

namespace App\Domain\Exports;

use App\Models\Project;
use App\Models\Team;
use App\Models\TeamExport;
use App\Models\User;
use App\Support\Tenancy\AccessibleProjects;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * What every export section may read (ADR-024 §2): the team, the requesting Owner as re-resolved
 * when the job runs, and the projects that Owner can see right now.
 */
final class TeamExportContext
{
    /** @var list<string>|null */
    private ?array $projectIds = null;

    public function __construct(
        public readonly Team $team,
        public readonly User $actor,
        public readonly TeamExport $export,
        private readonly AccessibleProjects $accessibleProjects,
    ) {}

    /**
     * The team's projects the actor can see (AccessibleProjects; the Owner sees all of them, private
     * ones included, but never trashed ones).
     *
     * @return Builder<Project>
     */
    public function projects(): Builder
    {
        return $this->accessibleProjects->inTeam($this->team, $this->actor);
    }

    /**
     * IDs of projects(), resolved once per export so every section sees the same set.
     *
     * @return list<string>
     */
    public function projectIds(): array
    {
        if ($this->projectIds === null) {
            $ids = [];

            foreach ($this->projects()->orderBy('projects.id')->pluck('projects.id') as $id) {
                if (is_string($id)) {
                    $ids[] = $id;
                }
            }

            $this->projectIds = $ids;
        }

        return $this->projectIds;
    }

    /** RFC 3339 UTC, as in the API (API_SPEC.md §0.6). */
    public static function timestamp(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : Carbon::instance($at)->utc()->toIso8601ZuluString();
    }
}
