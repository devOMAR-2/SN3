<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

it('stores a valid guest choice in the session and redirects back', function (string $locale) {
    $this->from('/somewhere')
        ->put('/locale', ['locale' => $locale])
        ->assertRedirect('/somewhere')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('locale', $locale);
})->with(['ar', 'en']);

it('applies the stored choice to the next page', function () {
    $this->withHeader('Accept-Language', 'ar')->from('/')->put('/locale', ['locale' => 'en']);

    $this->withHeader('Accept-Language', 'ar')
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en')->where('dir', 'ltr'))
        ->assertSee('<html lang="en" dir="ltr">', escape: false);
});

it('rejects unsupported or missing values without touching the session', function (array $payload) {
    $this->withSession(['locale' => 'en'])
        ->from('/')
        ->put('/locale', $payload)
        ->assertRedirect('/')
        ->assertSessionHasErrors('locale')
        ->assertSessionHas('locale', 'en');
})->with([
    'missing' => [[]],
    'unknown code' => [['locale' => 'fr']],
    'region tag' => [['locale' => 'ar-SA']],
    'wrong case' => [['locale' => 'AR']],
    'array' => [['locale' => ['en']]],
    'empty' => [['locale' => '']],
]);

it('only accepts PUT', function (string $method) {
    $this->call($method, '/locale', ['locale' => 'en'])->assertMethodNotAllowed();
})->with(['GET', 'POST', 'PATCH', 'DELETE']);

it('is a CSRF-protected web route', function () {
    $route = Route::getRoutes()->getByName('locale.update');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('web');
});

it('refuses a request without a valid CSRF token', function () {
    // The framework skips CSRF checks in unit tests; re-enable them for this request.
    $this->app->instance(ValidateCsrfToken::class, new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });

    $this->put('/locale', ['locale' => 'en'])->assertStatus(419);

    $this->withSession(['_token' => 'known-token'])
        ->from('/')
        ->put('/locale', ['locale' => 'en', '_token' => 'known-token'])
        ->assertRedirect('/')
        ->assertSessionHas('locale', 'en');
});

it('is throttled', function () {
    foreach (range(1, 30) as $attempt) {
        $this->from('/')->put('/locale', ['locale' => 'en'])->assertRedirect('/');
    }

    $this->from('/')->put('/locale', ['locale' => 'en'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');
});
