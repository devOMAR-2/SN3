<?php

namespace App\Domain\Teams\Actions;

use App\Domain\Teams\TeamRole;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a team with its creator as Owner (FR-TEAM-001, API_SPEC.md §3). Team and owner membership
 * are inserted in one transaction; the database rejects a team that commits without them.
 *
 * Slugs are deployment-wide unique, trashed teams included. A requested slug that is taken is a
 * 409 conflict. Without one, a slug is derived from the name (with a random suffix when needed),
 * and a concurrent insert of the same slug is retried with a new suffix.
 */
final class CreateTeam
{
    private const ATTEMPTS = 5;

    public function handle(User $owner, string $name, ?string $slug = null, ?string $description = null): Team
    {
        $requested = $slug !== null;
        $base = $slug ?? self::baseSlug($name);

        for ($attempt = 1; ; $attempt++) {
            $candidate = $requested || ($attempt === 1 && ! self::slugTaken($base)) ? $base : self::suffixed($base);

            if ($requested && self::slugTaken($candidate)) {
                throw new DomainConflictException('Team slug is already taken.');
            }

            try {
                return DB::transaction(fn (): Team => $this->insert($owner, $name, $candidate, $description));
            } catch (UniqueConstraintViolationException $e) {
                if ($requested || $attempt >= self::ATTEMPTS || ! str_contains($e->getMessage(), 'teams_slug_unique')) {
                    throw $requested ? new DomainConflictException('Team slug is already taken.', previous: $e) : $e;
                }
            }
        }
    }

    private function insert(User $owner, string $name, string $slug, ?string $description): Team
    {
        $team = new Team;
        $team->forceFill([
            'name' => $name,
            'slug' => $slug,
            'description' => $description === '' ? null : $description,
            'owner_user_id' => $owner->id,
        ])->save();

        (new TeamMembership)->forceFill([
            'team_id' => $team->id,
            'user_id' => $owner->id,
            'role' => TeamRole::Owner,
            'joined_at' => now(),
        ])->save();

        return $team;
    }

    /**
     * ASCII slug from the name, at most 32 characters, so a 7-character suffix still fits in 40.
     * Names without Latin transliteration (or too short) fall back to "team".
     */
    private static function baseSlug(string $name): string
    {
        $slug = trim(Str::limit(Str::slug($name), 32, ''), '-');

        return strlen($slug) >= 3 ? $slug : 'team';
    }

    private static function suffixed(string $base): string
    {
        return $base.'-'.Str::lower(Str::random(6));
    }

    private static function slugTaken(string $slug): bool
    {
        return Team::withTrashed()->where('slug', $slug)->exists();
    }
}
