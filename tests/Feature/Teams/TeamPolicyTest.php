<?php

use App\Domain\Teams\TeamRole;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

// FND-005: team role decisions (API_SPEC.md §3 permission column) for Owner, Admin, Member,
// Viewer and a user from an unrelated team.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->users = ['owner' => $this->team->owner];

    foreach ([TeamRole::Admin, TeamRole::Member, TeamRole::Viewer] as $role) {
        $this->users[$role->value] = TeamMembership::factory()->for($this->team)->role($role)->create()->user;
    }

    $this->users['outsider'] = Team::factory()->create()->owner;
});

/**
 * @return array<string, bool> expected result per actor
 */
function allowed(string ...$actors): array
{
    return collect(['owner', 'admin', 'member', 'viewer', 'outsider'])
        ->mapWithKeys(fn (string $actor) => [$actor => in_array($actor, $actors, true)])
        ->all();
}

it('decides team-level abilities by role', function (string $ability, array $expected) {
    foreach ($expected as $actor => $allowed) {
        expect(Gate::forUser($this->users[$actor])->allows($ability, $this->team))
            ->toBe($allowed, "{$actor} → {$ability}");
    }
})->with([
    'view' => ['view', allowed('owner', 'admin', 'member', 'viewer')],
    'update' => ['update', allowed('owner', 'admin')],
    'invite' => ['invite', allowed('owner', 'admin')],
    'createProject' => ['createProject', allowed('owner', 'admin')],
    'transferOwnership' => ['transferOwnership', allowed('owner')],
    'archive' => ['archive', allowed('owner')],
    'delete' => ['delete', allowed('owner')],
    'restore' => ['restore', allowed('owner')],
    'export' => ['export', allowed('owner')],
]);

it('lets only the Owner change roles, and never the Owner own role', function () {
    $owner = $this->users['owner'];

    expect(Gate::forUser($owner)->allows('changeMemberRole', [$this->team, $this->users['member']]))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('changeMemberRole', [$this->team, $owner]))->toBeFalse()
        ->and(Gate::forUser($this->users['admin'])->allows('changeMemberRole', [$this->team, $this->users['member']]))->toBeFalse()
        ->and(Gate::forUser($this->users['outsider'])->allows('changeMemberRole', [$this->team, $this->users['member']]))->toBeFalse();
});

it('lets the Owner remove others and any member try to leave (RemoveMember refuses the Owner)', function () {
    $owner = $this->users['owner'];
    $remove = fn (User $actor, User $member) => Gate::forUser($actor)->allows('removeMember', [$this->team, $member]);

    expect($remove($owner, $this->users['admin']))->toBeTrue()
        ->and($remove($owner, $owner))->toBeTrue()
        ->and($remove($this->users['admin'], $this->users['member']))->toBeFalse()
        ->and($remove($this->users['viewer'], $this->users['viewer']))->toBeTrue()
        ->and($remove($this->users['member'], $owner))->toBeFalse()
        ->and($remove($owner, $this->users['outsider']))->toBeFalse()
        ->and($remove($this->users['outsider'], $this->users['outsider']))->toBeFalse();
});
