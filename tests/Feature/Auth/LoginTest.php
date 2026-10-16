<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

// TEST-FR-AUTH-002 (sign in/out), FND-004 acceptance: sessions, CSRF, throttling.

uses(RefreshDatabase::class);

function loginAs(string $email = 'layla@example.com', string $password = 'a long enough password', array $extra = [])
{
    return test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password, ...$extra]);
}

beforeEach(function () {
    $this->user = User::factory()->create([
        'email' => 'layla@example.com',
        'password' => 'a long enough password',
        'locale' => 'en',
    ]);
});

it('signs in, regenerates the session id and returns only the caller profile', function () {
    $this->startSession();
    $before = session()->getId();

    $response = loginAs(' Layla@EXAMPLE.com ')->assertOk();

    $this->assertAuthenticatedAs($this->user);
    expect(session()->getId())->not->toBe($before);

    $response->assertJsonPath('data.id', $this->user->id)
        ->assertJsonPath('data.email', 'layla@example.com')
        ->assertJsonPath('data.locale', 'en')
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.remember_token')
        ->assertJsonStructure(['meta' => ['request_id']]);

    expect($response->getContent())->not->toContain('token');
});

it('returns the same generic error for a wrong password, an unknown email and a disabled account', function () {
    User::factory()->disabled()->create(['email' => 'off@example.com', 'password' => 'a long enough password']);

    $responses = [
        loginAs(password: 'wrong password!!'),
        loginAs(email: 'nobody@example.com'),
        loginAs(email: 'off@example.com'),
    ];

    foreach ($responses as $response) {
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.fields', ['email' => ['credentials']]);
    }

    $this->assertGuest();
});

it('locks out an email+IP after repeated failures with rate_limited and Retry-After', function () {
    foreach (range(1, 5) as $attempt) {
        loginAs(password: 'wrong password!!')->assertStatus(422);
    }

    loginAs()->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertHeader('Retry-After');

    $this->assertGuest();
});

it('limits attempts per IP across many emails', function () {
    config(['sn3.rate_limits.login_per_minute' => 1]);

    foreach (range(1, 4) as $i) {
        loginAs(email: "probe{$i}@example.com")->assertStatus(422);
    }

    loginAs(email: 'probe5@example.com')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
});

it('clears the failure counter after a successful sign-in', function () {
    foreach (range(1, 4) as $attempt) {
        loginAs(password: 'wrong password!!')->assertStatus(422);
    }

    loginAs()->assertOk();
    $this->postJson('/api/v1/auth/logout')->assertNoContent();

    foreach (range(1, 4) as $attempt) {
        loginAs(password: 'wrong password!!')->assertStatus(422);
    }

    loginAs()->assertOk();
});

it('rejects sign-in without a CSRF token', function () {
    enforceCsrf();

    loginAs()->assertStatus(419)->assertJsonPath('error.code', 'csrf_invalid');
    $this->assertGuest();
});

it('accepts sign-in with a valid CSRF token', function () {
    enforceCsrf();

    $this->withSession(['_token' => 'known-token'])
        ->withHeader('X-CSRF-TOKEN', 'known-token')
        ->postJson('/api/v1/auth/login', ['email' => 'layla@example.com', 'password' => 'a long enough password'])
        ->assertOk();
});

it('sets a remember-me cookie only when asked', function () {
    $without = loginAs();
    expect(collect($without->headers->getCookies())->contains(fn ($c) => str_starts_with($c->getName(), 'remember_web_')))->toBeFalse();

    $this->postJson('/api/v1/auth/logout');

    $with = loginAs(extra: ['remember' => true]);
    expect(collect($with->headers->getCookies())->contains(fn ($c) => str_starts_with($c->getName(), 'remember_web_')))->toBeTrue();
});

it('signs out, invalidates the session and rotates the CSRF token', function () {
    $this->actingAs($this->user);
    $this->startSession();
    $token = session()->token();
    $id = session()->getId();

    $this->postJson('/api/v1/auth/logout')->assertNoContent();

    $this->assertGuest();
    expect(session()->token())->not->toBe($token)
        ->and(session()->getId())->not->toBe($id);
});

it('requires authentication to sign out', function () {
    $this->postJson('/api/v1/auth/logout')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});
