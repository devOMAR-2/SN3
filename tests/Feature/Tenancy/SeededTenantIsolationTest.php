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
    expect(User::query()->count())->toBe(count($this->fixture['users']))
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
        ->toBe(collect($this->fixture['users'])->pluck('id')->sort()->values()->all())
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

        $listed = $this->actingAs($user)->getJson('/api/v1/me/teams')->assertOk()->json('data');

        expect(collect($listed)->pluck('role', 'id')->sortKeys()->all())
            ->toBe($expected->sortKeys()->all(), "{$userData['key']} lists the wrong teams");

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

it('denies cross-tenant team abilities for every role', function () {
    $alpha = Team::query()->findOrFail(collect($this->fixture['teams'])->firstWhere('key', 'alpha')['id']);

    foreach (['beta_owner', 'beta_member', 'outsider'] as $key) {
        $user = User::query()->findOrFail(collect($this->fixture['users'])->firstWhere('key', $key)['id']);

        expect($alpha->roleOf($user))->toBeNull();

        foreach (['view', 'update', 'invite', 'createProject', 'transferOwnership', 'archive', 'delete', 'restore', 'export'] as $ability) {
            expect($user->can($ability, $alpha))->toBeFalse("{$key} → {$ability} on alpha");
        }
    }

    $shared = User::query()->findOrFail(collect($this->fixture['users'])->firstWhere('key', 'shared')['id']);
    expect($alpha->roleOf($shared))->toBe(TeamRole::Viewer)
        ->and($shared->can('update', $alpha))->toBeFalse();
});

it('refuses to seed production data', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new E2ETenantSeeder)->run())->toThrow(RuntimeException::class);
});
