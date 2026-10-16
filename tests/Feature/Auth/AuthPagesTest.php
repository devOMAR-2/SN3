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

it('passes the reset token and email to the reset page', function () {
    $this->get('/reset-password/abc123?email=omar%40example.com')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/ResetPassword')
            ->where('token', 'abc123')
            ->where('email', 'omar@example.com'));
});

it('redirects signed-in users away from guest pages', function (string $uri) {
    $this->actingAs(User::factory()->create())->get($uri)->assertRedirect('/');
})->with(['/login', '/register', '/forgot-password', '/reset-password/abc']);

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
