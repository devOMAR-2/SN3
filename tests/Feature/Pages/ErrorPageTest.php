<?php

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

// CORE-006 (ADR-026 §4): browser routes answer 403/404 with the localized Inertia `Error` page, which
// names no resource; /api/v1 keeps its JSON envelope; requests that matched no route keep the default.

uses(RefreshDatabase::class);

it('renders the Error page in the user’s language for a hidden team', function (string $locale, string $dir) {
    $team = Team::factory()->create();
    $user = $team->owner;
    $user->forceFill(['locale' => $locale])->save();

    $this->actingAs($user)->get('/t/not-my-team')
        ->assertNotFound()
        ->assertSee('<html lang="'.$locale.'" dir="'.$dir.'"', escape: false)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Error')
            ->where('status', 404)
            ->where('locale', $locale)
            // Shared props are present even though the binding failed before HandleInertiaRequests ran.
            ->where('auth.user.id', $user->id)
            ->has('workspace.teams', 1));
})->with([
    'Arabic' => ['ar', 'rtl'],
    'English' => ['en', 'ltr'],
]);

it('answers Inertia visits with the Error page too', function () {
    $team = Team::factory()->create();

    $this->actingAs($team->owner)
        ->get('/t/not-my-team', ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertNotFound()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'Error')
        ->assertJsonPath('props.status', 404);
});

it('keeps the JSON envelope on /api/v1', function () {
    $team = Team::factory()->create();

    $this->actingAs($team->owner)->get('/api/v1/teams/0192a000-0000-7000-8000-00000000ffff')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

it('leaves unmatched paths to the default handler', function () {
    $this->get('/definitely/not/a/route')->assertNotFound()->assertDontSee('"component":"Error"', escape: false);
});
