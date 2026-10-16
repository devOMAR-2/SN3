<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

// LIM-03: 120 authenticated requests per minute per user, then 429 rate_limited with Retry-After.

uses(RefreshDatabase::class);

it('allows 120 requests per minute per user, then answers 429 rate_limited with Retry-After', function () {
    $user = User::factory()->create();

    foreach (range(1, 120) as $request) {
        $this->actingAs($user)->getJson('/api/v1/me')->assertOk();
    }

    $this->actingAs($user)->getJson('/api/v1/me')
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertJsonStructure(['error' => ['code', 'message', 'request_id']])
        ->assertHeader('Retry-After');

    // The limit is per user: another account is unaffected.
    $this->actingAs(User::factory()->create())->getJson('/api/v1/me')->assertOk();

    // And it resets after the window.
    $this->travel(61)->seconds();
    $this->actingAs($user)->getJson('/api/v1/me')->assertOk();
});

it('applies to Inertia pages as well as the JSON API', function () {
    config(['sn3.rate_limits.requests_per_minute' => 3]);
    $user = User::factory()->create();

    foreach (range(1, 3) as $request) {
        $this->actingAs($user)->get('/settings')->assertOk();
    }

    $this->actingAs($user)->get('/settings')->assertStatus(429)->assertHeader('Retry-After');
});

it('caps bursts per second', function () {
    config(['sn3.rate_limits.burst_per_second' => 5]);
    $user = User::factory()->create();
    $this->freezeTime();

    foreach (range(1, 5) as $request) {
        $this->actingAs($user)->getJson('/api/v1/me')->assertOk();
    }

    $this->actingAs($user)->getJson('/api/v1/me')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
});

it('can be disabled by a self-hosted operator', function () {
    config(['sn3.rate_limits.requests_per_minute' => 0, 'sn3.rate_limits.burst_per_second' => 0]);
    $user = User::factory()->create();

    foreach (range(1, 130) as $request) {
        $this->actingAs($user)->getJson('/api/v1/me')->assertOk();
    }
});

it('does not rate-limit health probes', function () {
    config(['sn3.rate_limits.requests_per_minute' => 1]);

    foreach (range(1, 3) as $request) {
        $this->get('/health/live')->assertOk();
    }
});
