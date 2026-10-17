<?php

namespace Database\Seeders;

use App\Domain\Teams\TeamRole;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Deterministic test tenants for end-to-end and isolation tests (FND-008, docs/TESTING.md).
 *
 * Reads e2e/fixtures/tenants.json, the single source shared with the Playwright suite: two
 * unrelated teams with every role, one user in both teams and one in none, all with fixed UUIDs
 * and verified emails. Run it on an empty database:
 *
 *     php artisan migrate:fresh --seed --seeder="Database\Seeders\E2ETenantSeeder"
 *
 * The fixture password is public, so the seeder refuses to run outside local, testing and e2e.
 */
class E2ETenantSeeder extends Seeder
{
    public const FIXTURE = 'e2e/fixtures/tenants.json';

    public const ENVIRONMENTS = ['local', 'testing', 'e2e'];

    public function run(): void
    {
        if (! app()->environment(self::ENVIRONMENTS)) {
            throw new RuntimeException('E2ETenantSeeder only runs in the '.implode(', ', self::ENVIRONMENTS).' environments.');
        }

        $fixture = self::fixture();
        $password = Hash::make($fixture['password']);

        DB::transaction(function () use ($fixture, $password): void {
            $users = [];

            // `workflow_users` (no seeded team) are for E2E flows that change data, e.g. invitations.
            foreach ([...$fixture['users'], ...($fixture['workflow_users'] ?? [])] as $data) {
                $user = new User;
                $user->forceFill([
                    'id' => $data['id'],
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'locale' => $data['locale'],
                    'password' => $password,
                    'email_verified_at' => now(),
                ])->save();

                $users[$data['key']] = $user;
            }

            foreach ($fixture['teams'] as $data) {
                $team = new Team;
                $team->forceFill([
                    'id' => $data['id'],
                    'name' => $data['name'],
                    'slug' => $data['slug'],
                    'owner_user_id' => $users[$data['owner']]->id,
                ])->save();

                $roles = [$data['owner'] => TeamRole::Owner->value, ...$data['members']];

                foreach ($roles as $userKey => $role) {
                    (new TeamMembership)->forceFill([
                        'team_id' => $team->id,
                        'user_id' => $users[$userKey]->id,
                        'role' => TeamRole::from($role),
                        'joined_at' => now(),
                    ])->save();
                }
            }
        });
    }

    /**
     * @return array{password: string, users: list<array{key: string, id: string, name: string, email: string, locale: string}>, workflow_users?: list<array{key: string, id: string, name: string, email: string, locale: string}>, teams: list<array{key: string, id: string, name: string, slug: string, owner: string, members: array<string, string>}>}
     */
    public static function fixture(): array
    {
        $json = file_get_contents(base_path(self::FIXTURE));

        if ($json === false) {
            throw new RuntimeException('Cannot read '.self::FIXTURE.'.');
        }

        /** @var array{password: string, users: list<array{key: string, id: string, name: string, email: string, locale: string}>, workflow_users?: list<array{key: string, id: string, name: string, email: string, locale: string}>, teams: list<array{key: string, id: string, name: string, slug: string, owner: string, members: array<string, string>}>} $fixture */
        $fixture = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $fixture;
    }
}
