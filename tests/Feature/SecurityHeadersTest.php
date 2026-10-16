<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

// Baseline headers on every response (API_SPEC.md §13): no framing by other sites, no MIME sniffing.

uses(RefreshDatabase::class);

it('forbids framing by other sites and MIME sniffing everywhere', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
        ->assertHeader('X-Content-Type-Options', 'nosniff');
})->with([
    'Inertia page' => ['GET', '/login'],
    'API success' => ['GET', '/api/v1/auth/email/verify/x/y'],
    'API error' => ['GET', '/api/v1/teams'],
    'unknown route' => ['GET', '/no-such-page'],
    'health probe' => ['GET', '/health/live'],
]);
