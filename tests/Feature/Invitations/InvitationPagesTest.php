<?php

use App\Domain\Teams\TeamRole;
use App\Models\Team;
use App\Models\TeamMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

// CORE-002 pages: `/invite` (any visitor; the token stays in the fragment) and the team's invitations
// page (Owner and Admins, now a redirect to the members page; 403 for other members, 404 for everyone
// else), ADR-025 §8, ADR-026 §6.

uses(RefreshDatabase::class);

it('renders the invitation page for guests and signed-in users', function () {
    $this->get('/invite')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Invitations/Accept'));

    $this->actingAs(Team::factory()->create()->owner)
        ->get('/invite')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Invitations/Accept'));
});

it('sends the Owner and Admins from the invitations link to the members page, where the panel is', function () {
    $team = Team::factory()->create(['name' => 'Website']);
    $admin = TeamMembership::factory()->for($team)->role(TeamRole::Admin)->create()->user;

    foreach ([$team->owner, $admin] as $user) {
        $this->actingAs($user)->get("/teams/{$team->id}/invitations")
            ->assertRedirect("/t/{$team->slug}/members");
    }

    // The members page carries what the invitations panel needs (CORE-006, ADR-026 §6).
    $this->actingAs($team->owner)->get("/t/{$team->slug}/members")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Teams/Members')
            ->where('can.invite', true)
            ->where('can.invite_admin', true));

    $this->actingAs($admin)->get("/t/{$team->slug}/members")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.invite', true)->where('can.invite_admin', false));
});

it('refuses the invitations page to other members (403), outsiders (404) and guests', function () {
    $team = Team::factory()->create();

    foreach ([TeamRole::Member, TeamRole::Viewer] as $role) {
        $member = TeamMembership::factory()->for($team)->role($role)->create()->user;
        $this->actingAs($member)->get("/teams/{$team->id}/invitations")->assertForbidden();
    }

    $this->actingAs(Team::factory()->create()->owner)->get("/teams/{$team->id}/invitations")->assertNotFound();

    auth()->logout();
    $this->get("/teams/{$team->id}/invitations")->assertRedirect('/login');
});
