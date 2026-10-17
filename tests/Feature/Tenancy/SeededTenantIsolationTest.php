<?php

use App\Domain\Teams\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\E2ETenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// FND-008: the deterministic seeded tenants, and an exhaustive user × team matrix proving that no
// team is visible across tenants (DATABASE.md §16 DB-T01 for the resources that exist so far).

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(E2ETenantSeeder::class);
    $this->fixture = E2ETenantSeeder::fixture();
});

/**
 * @param  array{owner: string, members: array<string, string>}  $team
 * @return array<string, string> user key => role
 */
function rolesIn(array $team): array
{
    return [$team['owner'] => 'owner', ...$team['members']];
}

it('seeds two unrelated teams with every role, a two-team user and an outsider', function () {
    // Plus the CORE-006 journey users, who belong to no team.
    expect(User::query()->count())->toBe(count($this->fixture['users']) + count($this->fixture['journey_users'] ?? []))
        ->and(Team::query()->count())->toBe(2)
        ->and(User::query()->whereNull('email_verified_at')->count())->toBe(0);

    foreach ($this->fixture['teams'] as $data) {
        $team = Team::query()->findOrFail($data['id']);
        $roles = $team->memberships()->with('user')->get()
            ->mapWithKeys(fn ($m) => [$m->user->email => $m->role->value])->sort()->all();

        $expected = collect(rolesIn($data))
            ->mapWithKeys(fn (string $role, string $key) => [collect($this->fixture['users'])->firstWhere('key', $key)['email'] => $role])
            ->sort()->all();

        expect($roles)->toBe($expected);
    }

    // The deferred owner checks pass, as they would at COMMIT.
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('is deterministic: the same fixed IDs every run', function () {
    expect(User::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect([...$this->fixture['users'], ...($this->fixture['journey_users'] ?? [])])->pluck('id')->sort()->values()->all())
        ->and(Team::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect($this->fixture['teams'])->pluck('id')->sort()->values()->all());
});

it('signs every seeded user in with the fixture password', function () {
    foreach ($this->fixture['users'] as $data) {
        $this->postJson('/api/v1/auth/login', ['email' => $data['email'], 'password' => $this->fixture['password']])
            ->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
    }
});

it('lets every user see exactly their own teams, and nothing of any other team', function () {
    foreach ($this->fixture['users'] as $userData) {
        $user = User::query()->findOrFail($userData['id']);

        $expected = collect($this->fixture['teams'])
            ->filter(fn (array $team) => array_key_exists($userData['key'], rolesIn($team)))
            ->mapWithKeys(fn (array $team) => [$team['id'] => rolesIn($team)[$userData['key']]]);

        foreach (['/api/v1/me/teams', '/api/v1/teams'] as $path) {
            $listed = $this->actingAs($user)->getJson($path)->assertOk()->json('data');

            expect(collect($listed)->pluck('role', 'id')->sortKeys()->all())
                ->toBe($expected->sortKeys()->all(), "{$userData['key']} lists the wrong teams at {$path}");
        }

        foreach ($this->fixture['teams'] as $team) {
            $response = $this->actingAs($user)->getJson("/api/v1/teams/{$team['id']}");

            if ($expected->has($team['id'])) {
                $response->assertOk()->assertJsonPath('data.role', $expected[$team['id']]);
            } else {
                $response->assertNotFound()->assertJsonPath('error.code', 'not_found');
                expect($response->getContent())->not->toContain($team['name'])
                    ->and($response->getContent())->not->toContain($team['id']);
            }
        }
    }
});

it('denies every team ability to every non-member, in both directions', function () {
    $abilities = ['view', 'update', 'invite', 'createProject', 'transferOwnership', 'archive', 'delete', 'restore', 'export'];
    $users = User::query()->get()->keyBy('id');

    foreach ($this->fixture['teams'] as $data) {
        $team = Team::query()->findOrFail($data['id']);
        $members = collect(rolesIn($data))->keys()
            ->map(fn (string $key) => $users[collect($this->fixture['users'])->firstWhere('key', $key)['id']]);
        $someMember = $members->first();

        foreach ($users->reject(fn (User $u) => $members->contains('id', $u->id)) as $outsider) {
            expect($team->roleOf($outsider))->toBeNull();

            foreach ($abilities as $ability) {
                expect($outsider->can($ability, $team))->toBeFalse("{$outsider->email} → {$ability} on {$data['key']}");
            }

            expect($outsider->can('changeMemberRole', [$team, $someMember]))->toBeFalse()
                ->and($outsider->can('removeMember', [$team, $someMember]))->toBeFalse()
                ->and($outsider->can('removeMember', [$team, $outsider]))->toBeFalse();
        }
    }

    $alpha = Team::query()->findOrFail(collect($this->fixture['teams'])->firstWhere('key', 'alpha')['id']);
    $shared = $users[collect($this->fixture['users'])->firstWhere('key', 'shared')['id']];
    expect($alpha->roleOf($shared))->toBe(TeamRole::Viewer)
        ->and($shared->can('update', $alpha))->toBeFalse();
});

it('refuses to seed outside local, testing and e2e', function (string $environment) {
    $users = User::query()->count();
    app()->detectEnvironment(fn () => $environment);

    // The message pins the guard itself; a second seed would otherwise fail on duplicate keys too.
    expect(fn () => (new E2ETenantSeeder)->run())
        ->toThrow(RuntimeException::class, 'E2ETenantSeeder only runs in the local, testing, e2e environments.')
        ->and(User::query()->count())->toBe($users);
})->with(['production', 'staging']);
