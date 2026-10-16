<?php

use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Support\Tenancy\AccessibleProjects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

// CORE-003: who can see, manage and write to a project. The full team role × visibility × explicit
// membership matrix (BRD §7 and its footnote, API_SPEC.md §12/§17, DATABASE.md §19, DECIDED-003),
// plus non-disclosure of private projects in lists, pagination, counts and 404s.
// TEST-FR-PROJ-002..006; acceptance scenarios AUTH-02, AUTH-03; DATABASE.md §16 DB-T01, DB-T02.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create(['name' => 'Website']);
    $this->owner = $this->team->owner;
    $this->otherTeam = Team::factory()->create();
    $this->outsider = $this->otherTeam->owner;
});

function joinTeam(Team $team, TeamRole $role): User
{
    return TeamMembership::factory()->for($team)->role($role)->create()->user;
}

function joinProject(Project $project, User $user, ProjectRole $role = ProjectRole::Contributor): ProjectMembership
{
    return ProjectMembership::factory()->for($project)->member($user)->role($role)->create();
}

/**
 * [team role, visibility, explicit project role, can view, can manage, can contribute]
 *
 * "Manage" is PATCH /projects and the member endpoints; "contribute" is the write ability later
 * task endpoints use (CORE-005), checked through the policy.
 */
dataset('access matrix', [
    'Owner, team, no membership' => ['owner', 'team', null, true, true, true],
    'Owner, team, manager' => ['owner', 'team', 'manager', true, true, true],
    'Owner, team, contributor' => ['owner', 'team', 'contributor', true, true, true],
    'Owner, private, no membership' => ['owner', 'private', null, true, true, true],
    'Owner, private, manager' => ['owner', 'private', 'manager', true, true, true],
    'Owner, private, contributor' => ['owner', 'private', 'contributor', true, true, true],
    'Admin, team, no membership' => ['admin', 'team', null, true, true, true],
    'Admin, team, manager' => ['admin', 'team', 'manager', true, true, true],
    'Admin, team, contributor' => ['admin', 'team', 'contributor', true, true, true],
    'Admin, private, no membership' => ['admin', 'private', null, false, false, false],
    'Admin, private, manager' => ['admin', 'private', 'manager', true, true, true],
    'Admin, private, contributor' => ['admin', 'private', 'contributor', true, false, true],
    'Member, team, no membership' => ['member', 'team', null, true, false, true],
    'Member, team, manager' => ['member', 'team', 'manager', true, true, true],
    'Member, team, contributor' => ['member', 'team', 'contributor', true, false, true],
    'Member, private, no membership' => ['member', 'private', null, false, false, false],
    'Member, private, manager' => ['member', 'private', 'manager', true, true, true],
    'Member, private, contributor' => ['member', 'private', 'contributor', true, false, true],
    'Viewer, team, no membership' => ['viewer', 'team', null, true, false, false],
    'Viewer, team, manager' => ['viewer', 'team', 'manager', true, false, false],
    'Viewer, team, contributor' => ['viewer', 'team', 'contributor', true, false, false],
    'Viewer, private, no membership' => ['viewer', 'private', null, false, false, false],
    'Viewer, private, manager' => ['viewer', 'private', 'manager', true, false, false],
    'Viewer, private, contributor' => ['viewer', 'private', 'contributor', true, false, false],
    'other team, team' => ['outsider', 'team', null, false, false, false],
    'other team, private' => ['outsider', 'private', null, false, false, false],
]);

it('applies the effective permission matrix to every project endpoint', function (string $role, string $visibility, ?string $projectRole, bool $view, bool $manage, bool $contribute) {
    $project = Project::factory()->for($this->team)->create(['name' => 'Payroll revamp', 'visibility' => $visibility]);
    $actor = match ($role) {
        'owner' => $this->owner,
        'outsider' => $this->outsider,
        default => joinTeam($this->team, TeamRole::from($role)),
    };

    if ($projectRole !== null) {
        joinProject($project, $actor, ProjectRole::from($projectRole));
    }

    $newcomer = joinTeam($this->team, TeamRole::Member);
    $existing = joinTeam($this->team, TeamRole::Member);
    joinProject($project, $existing);

    // Hidden is 404 (never 403); visible but not allowed is 403.
    $writeStatus = $manage ? null : ($view ? 403 : 404);

    // Detail and member list.
    $detail = $this->actingAs($actor)->getJson("/api/v1/projects/{$project->id}");
    $view ? $detail->assertOk()->assertJsonPath('data.role', $projectRole) : $detail->assertNotFound()->assertJsonPath('error.code', 'not_found');
    expect($detail->getContent() !== false && str_contains($detail->getContent(), 'Payroll revamp'))->toBe($view);

    $members = $this->actingAs($actor)->getJson("/api/v1/projects/{$project->id}/members");
    $view ? $members->assertOk() : $members->assertNotFound();

    // Team project list (another team's member cannot list this team at all).
    $list = $this->actingAs($actor)->getJson("/api/v1/teams/{$this->team->id}/projects");
    if ($role === 'outsider') {
        $list->assertNotFound();
    } else {
        expect(collect($list->assertOk()->json('data'))->pluck('id')->contains($project->id))->toBe($view);
    }

    // Add a team member (PUT) and remove an existing project member (DELETE).
    $put = $this->actingAs($actor)->putJson("/api/v1/projects/{$project->id}/members/{$newcomer->id}", ['role' => 'contributor']);
    $manage ? $put->assertCreated() : $put->assertStatus($writeStatus);
    expect($project->memberships()->where('user_id', $newcomer->id)->exists())->toBe($manage);

    $delete = $this->actingAs($actor)->deleteJson("/api/v1/projects/{$project->id}/members/{$existing->id}");
    $manage ? $delete->assertNoContent() : $delete->assertStatus($writeStatus);
    expect($project->memberships()->where('user_id', $existing->id)->exists())->toBe(! $manage);

    // Settings and visibility (PATCH).
    $patch = $this->actingAs($actor)->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Renamed'], ['If-Match' => '"1"']);
    $manage ? $patch->assertOk()->assertJsonPath('data.version', 2) : $patch->assertStatus($writeStatus);
    expect($project->refresh()->name)->toBe($manage ? 'Renamed' : 'Payroll revamp');

    // Task-level writes (CORE-005 builds on this ability).
    $fresh = Project::query()->findOrFail($project->id);
    expect(Gate::forUser($actor)->allows('view', $fresh))->toBe($view)
        ->and(Gate::forUser($actor)->allows('update', $fresh))->toBe($manage)
        ->and(Gate::forUser($actor)->allows('manageMembers', $fresh))->toBe($manage)
        ->and(Gate::forUser($actor)->allows('contribute', $fresh))->toBe($contribute);
})->with('access matrix');

// --- Non-disclosure (AUTH-02, DB-T02) ---------------------------------------------------------------

it('answers a hidden private project exactly like a project that does not exist', function () {
    $admin = joinTeam($this->team, TeamRole::Admin);
    $private = Project::factory()->for($this->team)->private()->create(['name' => 'Secret merger', 'project_key' => 'SECRET']);

    $hidden = $this->actingAs($admin)->getJson("/api/v1/projects/{$private->id}")->assertNotFound();
    $missing = $this->actingAs($admin)->getJson('/api/v1/projects/'.Str::uuid7())->assertNotFound();
    $malformed = $this->actingAs($admin)->getJson('/api/v1/projects/not-a-uuid')->assertNotFound();

    foreach ([$hidden, $missing, $malformed] as $response) {
        expect(array_keys($response->json('error')))->toBe(['code', 'message', 'request_id'])
            ->and($response->json('error.code'))->toBe('not_found')
            ->and($response->json('error.message'))->toBe($missing->json('error.message'));
    }

    expect((string) $hidden->getContent())->not->toContain('Secret merger')
        ->not->toContain('SECRET')
        ->not->toContain($private->id);
});

it('hides private projects from an Admin list without leaking them through pagination', function () {
    $admin = joinTeam($this->team, TeamRole::Admin);

    // Interleave visible and private projects in creation order.
    $visible = [];
    foreach (range(1, 9) as $i) {
        $this->travel(1)->minutes();
        $project = Project::factory()->for($this->team)->create(['visibility' => $i % 3 === 0 ? 'team' : 'private', 'name' => "Project {$i}"]);
        if ($project->visibility->value === 'team') {
            $visible[] = $project->id;
        }
    }

    $seen = [];
    $uri = "/api/v1/teams/{$this->team->id}/projects?limit=2";
    $pages = 0;

    do {
        $page = $this->actingAs($admin)->getJson($uri)->assertOk();
        $seen = [...$seen, ...collect($page->json('data'))->pluck('id')->all()];
        expect((string) $page->getContent())->not->toMatch('/Project [124578]"/');
        $cursor = $page->json('meta.next_cursor');
        $uri = "/api/v1/teams/{$this->team->id}/projects?limit=2&cursor={$cursor}";
        $pages++;
    } while ($page->json('meta.has_more'));

    expect($seen)->toBe(array_reverse($visible))
        ->and($pages)->toBe(2);

    // A page holding all visible projects reports nothing more, although six private ones follow.
    $this->actingAs($admin)->getJson("/api/v1/teams/{$this->team->id}/projects?limit=3")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.has_more', false)
        ->assertJsonPath('meta.next_cursor', null);

    // The Owner pages over every project.
    $this->actingAs($this->owner)->getJson("/api/v1/teams/{$this->team->id}/projects?limit=100")
        ->assertOk()
        ->assertJsonCount(9, 'data');
});

it('gives the Team Owner every private project without a membership row (AUTH-03)', function () {
    $private = Project::factory()->for($this->team)->private()->create(['created_by' => joinTeam($this->team, TeamRole::Admin)->id]);

    expect($private->memberships()->count())->toBe(0);

    $this->actingAs($this->owner)->getJson("/api/v1/projects/{$private->id}")
        ->assertOk()
        ->assertJsonPath('data.role', null)
        ->assertJsonPath('data.visibility', 'private');
});

it('never lets a Team Admin outside a private project add themselves', function () {
    $admin = joinTeam($this->team, TeamRole::Admin);
    $private = Project::factory()->for($this->team)->private()->create();

    $this->actingAs($admin)->putJson("/api/v1/projects/{$private->id}/members/{$admin->id}", ['role' => 'manager'])
        ->assertNotFound();
    $this->actingAs($admin)->patchJson("/api/v1/projects/{$private->id}", ['visibility' => 'team'], ['If-Match' => '"1"'])
        ->assertNotFound();

    // As a contributor they see it, but still cannot promote themselves.
    joinProject($private, $admin);

    $this->actingAs($admin)->putJson("/api/v1/projects/{$private->id}/members/{$admin->id}", ['role' => 'manager'])
        ->assertForbidden();

    expect($private->roleOf($admin))->toBe(ProjectRole::Contributor)
        ->and($private->refresh()->isPrivate())->toBeTrue();
});

it('keeps another team out of every project endpoint, guessed IDs included (DB-T01)', function () {
    $project = Project::factory()->for($this->team)->create(['name' => 'Website relaunch']);
    $member = joinTeam($this->team, TeamRole::Member);
    joinProject($project, $member);

    $requests = [
        ['GET', "/api/v1/projects/{$project->id}", []],
        ['PATCH', "/api/v1/projects/{$project->id}", ['name' => 'Hijacked']],
        ['GET', "/api/v1/projects/{$project->id}/members", []],
        ['PUT', "/api/v1/projects/{$project->id}/members/{$this->outsider->id}", ['role' => 'manager']],
        ['DELETE', "/api/v1/projects/{$project->id}/members/{$member->id}", []],
        ['GET', "/api/v1/teams/{$this->team->id}/projects", []],
        ['POST', "/api/v1/teams/{$this->team->id}/projects", ['name' => 'Injected', 'key' => 'INJ']],
    ];

    foreach ($requests as [$method, $uri, $body]) {
        $response = $this->actingAs($this->outsider)->json($method, $uri, $body, ['If-Match' => '"1"']);

        $response->assertNotFound()->assertJsonPath('error.code', 'not_found');
        expect((string) $response->getContent())->not->toContain('Website relaunch');
    }

    expect($project->refresh()->name)->toBe('Website relaunch')
        ->and($project->memberships()->count())->toBe(1)
        ->and(Project::query()->count())->toBe(1);
});

it('hides the projects of a trashed team and trashed projects, even from the Owner', function () {
    $project = Project::factory()->for($this->team)->create();
    $trashed = Project::factory()->for($this->team)->create();
    $trashed->delete();

    $this->actingAs($this->owner)->getJson("/api/v1/projects/{$trashed->id}")->assertNotFound();
    $this->actingAs($this->owner)->getJson("/api/v1/teams/{$this->team->id}/projects")->assertJsonCount(1, 'data');

    $this->team->delete();

    $this->actingAs($this->owner)->getJson("/api/v1/projects/{$project->id}")->assertNotFound();
});

it('scopes any project query through AccessibleProjects::constrain', function () {
    $member = joinTeam($this->team, TeamRole::Member);
    $public = Project::factory()->for($this->team)->create();
    $joined = Project::factory()->for($this->team)->private()->create();
    Project::factory()->for($this->team)->private()->create();
    Project::factory()->for($this->otherTeam)->create();
    joinProject($joined, $member);

    $accessible = app(AccessibleProjects::class);

    expect($accessible->constrain(Project::query(), $member)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$public->id, $joined->id])->sort()->values()->all())
        ->and($accessible->constrain(Project::query(), $this->owner)->count())->toBe(3)
        ->and($accessible->constrain(Project::query(), $this->outsider)->count())->toBe(1);
});
