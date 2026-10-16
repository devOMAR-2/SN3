<?php

namespace Database\Factories;

use App\Domain\Teams\TeamRole;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Non-owner memberships by default; a team's owner membership comes from TeamFactory.
 *
 * @extends Factory<TeamMembership>
 */
class TeamMembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'role' => TeamRole::Member,
            'joined_at' => now(),
        ];
    }

    public function role(TeamRole $role): static
    {
        return $this->state(['role' => $role]);
    }
}
