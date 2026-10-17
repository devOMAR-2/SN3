<?php

use App\Domain\Teams\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/PageHelpers.php';

// CORE-006: team pages resolve `{teamSlug}` through AccessibleTeams (ADR-026 §2). Another team's page, a
// trashed team and a malformed slug are the same 404 Error page; Settings is 403 for Members and Viewers;
// the `can` flags are the policies' (TeamPolicy) and every write flag is off while the team is archived.

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create(['name' => 'Alpha Studio', 'description' => 'We build things.']);
    $this->other = Team::factory()->create(['name' => 'Hidden Beta Team']);
});

it('sends guests to the sign-in page', function (string $path) {
    $this->get($path)->assertRedirect('/login');
})->with([
    '/teams', '/teams/new', '/t/team-alpha', '/t/team-alpha/members', '/t/team-alpha/settings',
    '/t/team-alpha/projects', '/t/team-alpha/projects/new', '/t/team-alpha/p/WEB', '/t/team-alpha/p/WEB/settings',
    '/tasks/0192a000-0000-7000-8000-000000000001',
]);

/**
 * [team role, overview/members/projects, settings page, can.update, can.create_project, can.change_member_roles, can.transfer_ownership, can.archive, can.delete, can.export, can.leave]
 */
dataset('team roles', [
    'Owner' => ['owner', 200, 200, true, true, true, true, true, true, true, false],
    'Admin' => ['admin', 200, 200, true, true, false, false, false, false, false, true],
    'Member' => ['member', 200, 403, false, false, false, false, false, false, false, true],
    'Viewer' => ['viewer', 200, 403, false, false, false, false, false, false, false, true],
    'other team' => ['outsider', 404, 404, false, false, false, false, false, false, false, false],
]);

it('renders team pages by role and 404s for non-members', function (string $role, int $pages, int $settings, bool $update, bool $createProject, bool $roles, bool $transfer, bool $archive, bool $delete, bool $export, bool $leave) {
    $actor = pageActor($this->team, $role, $this->other);

    foreach (['', '/members', '/projects'] as $suffix) {
        $response = $this->actingAs($actor)->get("/t/{$this->team->slug}{$suffix}")->assertStatus($pages);

        if ($pages === 404) {
            $response->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404));
            expect($response->getContent())->not->toContain('Alpha Studio')->not->toContain($this->team->id);

            continue;
        }

        $response->assertInertia(fn (Assert $page) => $page
            ->where('team.id', $this->team->id)
            ->where('team.role', $role)
            ->where('can.update', $update)
            ->where('can.create_project', $createProject)
            ->where('can.change_member_roles', $roles)
            ->where('can.remove_other_members', $roles)
            ->where('can.transfer_ownership', $transfer)
            ->where('can.archive', $archive)
            ->where('can.unarchive', false)
            ->where('can.delete', $delete)
            ->where('can.export', $export)
            ->where('can.leave', $leave)
            ->where('can.view_settings', $settings === 200)
            ->where('lock', null));
    }

    $settingsPage = $this->actingAs($actor)->get("/t/{$this->team->slug}/settings")->assertStatus($settings);

    if ($settings === 200) {
        $settingsPage->assertInertia(fn (Assert $page) => $page->component('Teams/Settings'));
    } else {
        $settingsPage->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', $settings));
    }
})->with('team roles');

it('uses the right page component for each team route', function (string $suffix, string $component) {
    $this->actingAs($this->team->owner)
        ->get("/t/{$this->team->slug}{$suffix}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'overview' => ['', 'Teams/Show'],
    'members' => ['/members', 'Teams/Members'],
    'settings' => ['/settings', 'Teams/Settings'],
    'projects' => ['/projects', 'Projects/Index'],
    'new project' => ['/projects/new', 'Projects/Create'],
]);

it('answers trashed teams, unknown and malformed slugs with the same 404', function (string $slug) {
    $owner = $this->team->owner;
    $trashed = Team::factory()->ownedBy($owner)->create(['slug' => 'trashed-team', 'name' => 'Trashed Gamma']);
    $trashed->delete();

    $response = $this->actingAs($owner)->get('/t/'.$slug)->assertNotFound();
    $response->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404));
    expect($response->getContent())->not->toContain('Trashed Gamma');
})->with(['trashed-team', 'no-such-team', 'UPPER', 'a', 'bad_slug!', '0192a000-0000-7000-8000-00000000a001']);

it('resolves the slug case-sensitively and only among the caller’s teams', function () {
    $this->actingAs($this->team->owner)->get('/t/'.strtoupper($this->team->slug))->assertNotFound();
    $this->actingAs($this->team->owner)->get('/t/'.$this->other->slug)->assertNotFound();
});

it('lets only the Owner and Admins open project creation', function (string $role, int $status) {
    $this->actingAs(pageActor($this->team, $role, $this->other))
        ->get("/t/{$this->team->slug}/projects/new")
        ->assertStatus($status);
})->with([
    'Owner' => ['owner', 200],
    'Admin' => ['admin', 200],
    'Member' => ['member', 403],
    'Viewer' => ['viewer', 403],
    'other team' => ['outsider', 404],
]);

it('turns every write ability off in an archived team and offers unarchive to the Owner', function () {
    $this->team->forceFill(['archived_at' => now()])->save();
    $admin = pageTeamMember($this->team, TeamRole::Admin);

    $this->actingAs($this->team->owner)->get("/t/{$this->team->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('lock', 'team_archived')
            ->where('can.update', false)
            ->where('can.create_project', false)
            ->where('can.change_member_roles', false)
            ->where('can.transfer_ownership', false)
            ->where('can.archive', false)
            ->where('can.unarchive', true)
            ->where('can.delete', false)
            ->where('can.export', true)
            ->where('can.view_settings', true));

    $this->actingAs($admin)->get("/t/{$this->team->slug}")
        ->assertInertia(fn (Assert $page) => $page->where('can.unarchive', false)->where('can.leave', false));
});

it('shares only the user’s own teams with the team switcher', function () {
    $shared = pageTeamMember($this->team, TeamRole::Viewer);

    $response = $this->actingAs($shared)->get('/teams')->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Teams/Index')
        ->has('workspace.teams', 1)
        ->where('workspace.teams.0.id', $this->team->id)
        ->where('workspace.teams.0.role', 'viewer')
        ->where('workspace.has_more', false));
    expect($response->getContent())->not->toContain('Hidden Beta Team');
});

it('caps the switcher and says there are more', function () {
    $user = User::factory()->create();
    Team::factory()->count(51)->ownedBy($user)->create();

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (Assert $page) => $page->has('workspace.teams', 50)->where('workspace.has_more', true));
});

it('shares no workspace with guests', function () {
    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('workspace', null));
});

it('tells unverified users they cannot create a team yet', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get('/teams/new')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Teams/Create')->where('can.create_team', false));

    $this->actingAs($this->team->owner)
        ->get('/teams/new')
        ->assertInertia(fn (Assert $page) => $page->where('can.create_team', true));
});

it('has the API refuse every team action the page hides', function (string $role, int $pages, int $settings, bool $update, bool $createProject, bool $roles, bool $transfer, bool $archive, bool $delete, bool $export) {
    Queue::fake();
    $actor = pageActor($this->team, $role, $this->other);
    $target = pageTeamMember($this->team);
    $denied = $pages === 404 ? 404 : 403;

    $probes = [
        'update' => [$update, fn () => $this->actingAs($actor)->patchJson("/api/v1/teams/{$this->team->id}", ['description' => 'Probe'])],
        'create_project' => [$createProject, fn () => $this->actingAs($actor)->postJson("/api/v1/teams/{$this->team->id}/projects", ['name' => 'Probe', 'key' => 'PRB'.strtoupper(substr($role, 0, 3))])],
        'change_member_roles' => [$roles, fn () => $this->actingAs($actor)->patchJson("/api/v1/teams/{$this->team->id}/members/{$target->id}", ['role' => 'viewer'])],
        'export' => [$export, fn () => $this->actingAs($actor)->postJson("/api/v1/teams/{$this->team->id}/exports", [], ['Idempotency-Key' => (string) Str::uuid()])],
        // Last: archiving makes every later write a 423.
        'archive' => [$archive, fn () => $this->actingAs($actor)->postJson("/api/v1/teams/{$this->team->id}/archive")],
    ];

    foreach ($probes as $ability => [$allowed, $probe]) {
        $status = $probe()->status();
        expect($allowed ? $status >= 200 && $status < 300 : $status === $denied)->toBeTrue("{$ability}: {$status}");
    }

    expect($transfer)->toBe($role === 'owner')->and($delete)->toBe($role === 'owner');
})->with('team roles');

it('lists the Owner’s recoverable deleted teams only', function () {
    $owner = $this->team->owner;
    $recent = Team::factory()->ownedBy($owner)->create(['name' => 'Recently deleted']);
    $expired = Team::factory()->ownedBy($owner)->create(['name' => 'Long gone']);
    $foreign = Team::factory()->create(['name' => 'Someone else’s']);
    $adminOf = Team::factory()->create(['name' => 'Admin only']);
    $member = pageTeamMember($adminOf, TeamRole::Admin);

    $recent->delete();
    $expired->delete();
    $expired->forceFill(['deleted_at' => now()->subDays(30)->subMinute()])->save();
    $foreign->delete();
    $adminOf->delete();

    $this->actingAs($owner)->get('/teams')
        ->assertInertia(fn (Assert $page) => $page
            ->has('deletedTeams', 1)
            ->where('deletedTeams.0.id', $recent->id)
            ->where('deletedTeams.0.name', 'Recently deleted'));

    // An Admin of a deleted team cannot recover it, so it is not listed for them.
    $this->actingAs($member)->get('/teams')->assertInertia(fn (Assert $page) => $page->has('deletedTeams', 0));
});
