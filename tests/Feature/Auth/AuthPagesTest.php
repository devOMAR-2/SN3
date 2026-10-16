<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the public auth pages for guests', function (string $uri, string $component) {
    $this->get($uri)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component)
            ->where('auth.user', null)
            ->where('features.registration', true));
})->with([
    ['/login', 'Auth/Login'],
    ['/register', 'Auth/Register'],
    ['/forgot-password', 'Auth/ForgotPassword'],
]);

it('serves the reset page without the token ever reaching the server', function () {
    $this->get('/reset-password')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/ResetPassword')
            ->missing('token')
            ->missing('email'));

    // The old path-based form no longer exists.
    $this->get('/reset-password/abc123')->assertNotFound();
});

it('redirects signed-in users away from guest pages', function (string $uri) {
    $this->actingAs(User::factory()->create())->get($uri)->assertRedirect('/');
})->with(['/login', '/register', '/forgot-password']);

it('redirects guests to sign-in from authenticated pages', function (string $uri) {
    $this->get($uri)->assertRedirect('/login');
})->with(['/settings', '/verify-email']);

it('shows guests the welcome page and signed-in users their home', function () {
    $this->get('/')->assertInertia(fn (Assert $page) => $page->component('Welcome'));

    $user = User::factory()->create();
    $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page->component('Home')
        ->where('auth.user.id', $user->id)
        ->where('auth.user.email', $user->email)
        ->missing('auth.user.password'));
});

it('shares a flash status once', function () {
    $this->withSession(['status' => 'email_verified'])
        ->get('/login')
        ->assertInertia(fn (Assert $page) => $page->where('flash.status', 'email_verified'));
});

it('renders the settings and verification notice pages for signed-in users', function (string $uri, string $component) {
    $this->actingAs(User::factory()->unverified()->create())
        ->get($uri)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    ['/settings', 'Settings/Profile'],
    ['/verify-email', 'Auth/VerifyEmail'],
]);

it('serves the reset page to signed-in users too, so the link fragment is not redirected along', function () {
    $this->actingAs(User::factory()->create())->get('/reset-password')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/ResetPassword'));
});
