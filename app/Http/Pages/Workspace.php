<?php

namespace App\Http\Pages;

use App\Domain\Trash\TrashRetention;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\Tenancy\AccessibleTeams;

/**
 * Data the browser pages share (CORE-006, ADR-026): the team switcher's list and the trash entries.
 * Everything comes from the tenancy resolvers, so it never names a team or project the user cannot see.
 */
final class Workspace
{
    /** The team switcher shows at most this many teams; the rest are on /teams. */
    public const SWITCHER_LIMIT = 50;

    /**
     * The signed-in user's teams for the team switcher, by name.
     *
     * @return array{teams: list<array{id: string, name: string, slug: string, role: string|null, archived: bool}>, has_more: bool}
     */
    public static function switcher(User $user): array
    {
        $teams = app(AccessibleTeams::class)->query($user)
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::SWITCHER_LIMIT + 1)
            ->get();

        $entries = [];

        foreach ($teams->take(self::SWITCHER_LIMIT) as $team) {
            $entries[] = [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
                'role' => $team->roleOf($user)?->value,
                'archived' => $team->isArchived(),
            ];
        }

        return ['teams' => $entries, 'has_more' => $teams->count() > self::SWITCHER_LIMIT];
    }

    /**
     * @return array{id: string, name: string, slug: string, deleted_at: string|null, recoverable_until: string|null}
     */
    public static function trashedTeam(Team $team): array
    {
        return [
            'id' => $team->id,
            'name' => $team->name,
            'slug' => $team->slug,
            'deleted_at' => $team->deleted_at?->utc()->toIso8601ZuluString(),
            'recoverable_until' => $team->deleted_at?->copy()->addDays(TrashRetention::DAYS)->utc()->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array{id: string, name: string, key: string, visibility: string, deleted_at: string|null, recoverable_until: string|null}
     */
    public static function trashedProject(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'key' => $project->project_key,
            'visibility' => $project->visibility->value,
            'deleted_at' => $project->deleted_at?->utc()->toIso8601ZuluString(),
            'recoverable_until' => $project->deleted_at?->copy()->addDays(TrashRetention::DAYS)->utc()->toIso8601ZuluString(),
        ];
    }
}
