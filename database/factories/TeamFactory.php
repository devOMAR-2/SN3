<?php

namespace Database\Factories;

use App\Domain\Teams\TeamRole;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Teams always come with their owner membership, created in the same transaction because the
 * database rejects a committed team without it.
 *
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => 'team-'.Str::lower(Str::random(12)),
            'owner_user_id' => User::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Team $team): void {
            TeamMembership::factory()
                ->for($team)
                ->create(['user_id' => $team->owner_user_id, 'role' => TeamRole::Owner]);
        });
    }

    public function ownedBy(User $owner): static
    {
        return $this->state(['owner_user_id' => $owner->id]);
    }

    public function archived(): static
    {
        return $this->state(['archived_at' => now()]);
    }

    /**
     * @param  (callable(array<string, mixed>): array<string, mixed>)|array<string, mixed>  $attributes
     * @return Collection<int, Team>|Team
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return DB::transaction(fn () => parent::create($attributes, $parent));
    }
}
