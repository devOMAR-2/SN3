<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// CORE-006 (ADR-026 §4): browser routes answer 403/404 with the localized Inertia `Error` page, which
// names no resource; /api/v1 keeps its JSON envelope; requests that matched no route keep the default.

uses(RefreshDatabase::class);

/** Neither an Inertia page visit nor a full page carrying the Inertia `Error` component. */
function expectFrameworkErrorResponse(TestResponse $response): void
{
    $content = (string) $response->getContent();

    expect($response->headers->get('X-Inertia'))->toBeNull()
        ->and($content)->not->toContain('data-page')
        ->and($content)->not->toContain('"component":"Error"');
}

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
    expectFrameworkErrorResponse($this->get('/definitely/not/a/route')->assertNotFound());
});

it('keeps the framework answer for 419 and 429 on non-GET requests', function () {
    enforceCsrf();
    $team = Team::factory()->create();

    // 419: an expired CSRF token on PUT /locale is no page to show (its URL cannot be loaded).
    $expired = $this->actingAs($team->owner)
        ->put('/locale', ['locale' => 'en'], ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertStatus(419);
    expectFrameworkErrorResponse($expired);
});

it('keeps the framework answer for a throttled PUT', function () {
    foreach (range(1, 30) as $attempt) {
        $this->from('/')->put('/locale', ['locale' => 'en'])->assertRedirect('/');
    }

    $throttled = $this->from('/')->put('/locale', ['locale' => 'en'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');
    expectFrameworkErrorResponse($throttled);
});

it('renders a page-load 429 as the Error page and keeps Retry-After', function () {
    Route::middleware(['web', 'throttle:1,1'])->get('/__core006/throttled', fn () => 'ok');

    $this->get('/__core006/throttled')->assertOk();
    $this->get('/__core006/throttled')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 429));
});

it('falls back to the framework answer when the page cannot be rendered (database down)', function () {
    $team = Team::factory()->create();
    $user = $team->owner;

    // The shared props read the database (auth, workspace); simulate it failing.
    app()->instance(HandleInertiaRequests::class, new class extends HandleInertiaRequests
    {
        public function share(Request $request): array
        {
            throw new RuntimeException('database unavailable');
        }
    });

    expectFrameworkErrorResponse($this->actingAs($user)->get('/t/not-my-team')->assertNotFound());
});

it('shows server errors as the Error page, except in debug mode', function () {
    Route::middleware('web')->get('/__core006/boom', fn () => throw new RuntimeException('boom'));
    $team = Team::factory()->create();

    config(['app.debug' => false]);
    $this->actingAs($team->owner)->get('/__core006/boom')
        ->assertStatus(500)
        ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 500));

    config(['app.debug' => true]);
    expectFrameworkErrorResponse($this->actingAs($team->owner)->get('/__core006/boom')->assertStatus(500));
});
