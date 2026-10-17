<?php

use App\Domain\Projects\ProjectRole;
use App\Domain\Teams\TeamRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/PageHelpers.php';

// CORE-006 acceptance "role-based actions hidden AND server-denied" for projects (ADR-026 §3).
// `{projectKey}` resolves through AccessibleProjects inside the team: a private project is the same 404
// page as a missing one for everyone but its members and the Team Owner (DECIDED-003, DS-ACC-006/014).
// For every team role × visibility × project role, the `can` flags the page receives match the policy
// matrix of ADR-020 §5, and each flag that is off is refused by the API (403, or 404 when hidden),
// while each flag that is on is accepted.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->other = Team::factory()->create();
});

/**
 * [team role, visibility, project role, view, update (settings), manage members/workflow/labels, contribute]
 */
dataset('project page matrix', [
    'Owner, team' => ['owner', 'team', null, true, true, true, true],
    'Owner, private' => ['owner', 'private', null, true, true, true, true],
    'Admin, team' => ['admin', 'team', null, true, true, false, true],
    'Admin, team, manager' => ['admin', 'team', 'manager', true, true, true, true],
    'Admin, private' => ['admin', 'private', null, false, false, false, false],
    'Admin, private, manager' => ['admin', 'private', 'manager', true, true, true, true],
    'Admin, private, contributor' => ['admin', 'private', 'contributor', true, false, false, true],
    'Member, team' => ['member', 'team', null, true, false, false, true],
    'Member, team, manager' => ['member', 'team', 'manager', true, true, true, true],
    'Member, private' => ['member', 'private', null, false, false, false, false],
    'Member, private, contributor' => ['member', 'private', 'contributor', true, false, false, true],
    'Member, private, manager' => ['member', 'private', 'manager', true, true, true, true],
    'Viewer, team' => ['viewer', 'team', null, true, false, false, false],
    'Viewer, team, manager' => ['viewer', 'team', 'manager', true, false, false, false],
    'Viewer, private' => ['viewer', 'private', null, false, false, false, false],
    'Viewer, private, manager' => ['viewer', 'private', 'manager', true, false, false, false],
    'other team, team' => ['outsider', 'team', null, false, false, false, false],
]);

it('renders project pages with the policy abilities, and the API refuses what the page hides', function (string $role, string $visibility, ?string $projectRole, bool $view, bool $update, bool $manage, bool $contribute) {
    $project = pageProject($this->team, $visibility, ['name' => 'Payroll revamp']);
    $actor = pageActor($this->team, $role, $this->other);

    if ($projectRole !== null) {
        pageProjectMember($project, $actor, ProjectRole::from($projectRole));
    }

    $show = $this->actingAs($actor)->get(pageProjectUrl($this->team, $project));

    if (! $view) {
        $show->assertNotFound()->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404));
        expect($show->getContent())->not->toContain('Payroll revamp')->not->toContain($project->id);
        $this->actingAs($actor)->get(pageProjectUrl($this->team, $project, '/settings'))->assertNotFound();
    } else {
        $show->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Show')
            ->where('project.id', $project->id)
            ->where('project.role', $projectRole)
            ->has('statuses', 3)
            ->where('can.view_settings', $update || $manage)
            ->where('can.update', $update)
            ->where('can.manage_members', $manage)
            ->where('can.manage_workflow', $manage)
            ->where('can.manage_labels', $manage)
            ->where('can.restore_tasks', $manage)
            ->where('can.archive', $manage)
            ->where('can.delete', $manage)
            ->where('can.unarchive', false)
            ->where('can.contribute', $contribute)
            ->where('lock', null));

        $settings = $this->actingAs($actor)->get(pageProjectUrl($this->team, $project, '/settings'));
        ($update || $manage)
            ? $settings->assertOk()->assertInertia(fn (Assert $page) => $page->component('Projects/Settings')->where('settings.key_frozen', false))
            : $settings->assertForbidden()->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 403));
    }

    // The server agrees with every flag: allowed → success, hidden → 404, visible but not allowed → 403.
    $denied = $view ? 403 : 404;
    $newcomer = pageTeamMember($this->team);
    $probes = [
        'contribute' => [$contribute, fn () => $this->actingAs($actor)->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => 'Probe'], ['Idempotency-Key' => (string) Str::uuid()])],
        'manage_labels' => [$manage, fn () => $this->actingAs($actor)->postJson("/api/v1/projects/{$project->id}/labels", ['name' => 'Probe'])],
        'manage_workflow' => [$manage, fn () => $this->actingAs($actor)->postJson("/api/v1/projects/{$project->id}/statuses", ['name' => 'Probe', 'category' => 'todo'])],
        'manage_members' => [$manage, fn () => $this->actingAs($actor)->putJson("/api/v1/projects/{$project->id}/members/{$newcomer->id}", ['role' => 'contributor'])],
        'update' => [$update, fn () => $this->actingAs($actor)->patchJson("/api/v1/projects/{$project->id}", ['description' => 'Probe'], ['If-Match' => '"'.$project->fresh()?->version.'"'])],
    ];

    foreach ($probes as $ability => [$allowed, $probe]) {
        $status = $probe()->status();
        expect($allowed ? $status >= 200 && $status < 300 : $status === $denied)->toBeTrue("{$ability}: {$status}");
    }
})->with('project page matrix');

it('resolves the key case-insensitively, within the team only', function () {
    $project = pageProject($this->team, 'team', ['project_key' => 'WEB']);
    $foreign = pageProject($this->other, 'team', ['project_key' => 'APP']);
    $owner = $this->team->owner;

    $this->actingAs($owner)->get("/t/{$this->team->slug}/p/web")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('project.id', $project->id));
    // A project of another team behind this team's slug, and this team's key behind another slug.
    $this->actingAs($owner)->get("/t/{$this->team->slug}/p/APP")->assertNotFound();
    $this->actingAs($foreign->team->owner)->get("/t/{$this->other->slug}/p/WEB")->assertNotFound();
    $this->actingAs($owner)->get("/t/{$this->other->slug}/p/APP")->assertNotFound();
});

it('answers trashed projects and malformed keys with the same 404', function (string $key) {
    $project = pageProject($this->team, 'team', ['project_key' => 'GONE', 'name' => 'Gone project']);
    $project->delete();

    $response = $this->actingAs($this->team->owner)->get("/t/{$this->team->slug}/p/{$key}")->assertNotFound();
    expect($response->getContent())->not->toContain('Gone project');
})->with(['GONE', 'NOPE', '1ABC', 'A', 'WEB-1', 'KEYTOOLONG11', '%27%20OR%201%3D1']);

it('locks an archived project and an archived team’s projects', function () {
    $project = pageProject($this->team);
    $project->forceFill(['archived_at' => now()])->save();
    $owner = $this->team->owner;

    $this->actingAs($owner)->get(pageProjectUrl($this->team, $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('lock', 'project_archived')
            ->where('can.contribute', false)
            ->where('can.update', false)
            ->where('can.archive', false)
            ->where('can.unarchive', true)
            ->where('can.delete', false));

    $this->team->forceFill(['archived_at' => now()])->save();

    $this->actingAs($owner)->get(pageProjectUrl($this->team, $project))
        ->assertInertia(fn (Assert $page) => $page->where('lock', 'team_archived')->where('can.unarchive', false));

    // Hidden in the UI and refused by the server alike.
    $this->actingAs($owner)->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => 'x'], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(423);
});

it('marks the key frozen once a task exists', function () {
    $project = pageProject($this->team);
    Task::factory()->for($project)->create();

    $this->actingAs($this->team->owner)->get(pageProjectUrl($this->team, $project, '/settings'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('settings.key_frozen', true)
            ->where('workflowVersion', $project->fresh()?->workflow_version));
});

it('lists only the recoverable deleted projects of the team', function () {
    $owner = $this->team->owner;
    $admin = pageTeamMember($this->team, TeamRole::Admin);
    $manager = pageTeamMember($this->team);

    $teamVisible = pageProject($this->team, 'team', ['name' => 'Visible trashed']);
    $private = pageProject($this->team, 'private', ['name' => 'Secret trashed']);
    pageProjectMember($private, $manager, ProjectRole::Manager);
    pageProjectMember($teamVisible, $manager, ProjectRole::Manager);
    $expired = pageProject($this->team, 'team', ['name' => 'Expired trashed']);
    $live = pageProject($this->team, 'team', ['name' => 'Still live']);

    foreach ([$teamVisible, $private] as $project) {
        $project->delete();
    }
    $expired->forceFill(['deleted_at' => now()->subDays(31)])->save();

    $names = fn ($user) => collect($this->actingAs($user)->get("/t/{$this->team->slug}/projects")->viewData('page')['props']['deletedProjects'])->pluck('name')->sort()->values()->all();

    expect($names($owner))->toBe(['Secret trashed', 'Visible trashed'])
        ->and($names($manager))->toBe(['Secret trashed', 'Visible trashed'])
        // A Team Admin without a membership: not a manager of either, and never sees the private one.
        ->and($names($admin))->toBe([]);

    $page = $this->actingAs($admin)->get("/t/{$this->team->slug}/projects")->getContent();
    expect($page)->not->toContain('Secret trashed');
    expect($live->exists)->toBeTrue();
});
