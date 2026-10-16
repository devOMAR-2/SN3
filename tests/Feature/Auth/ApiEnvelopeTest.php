<?php

use Illuminate\Support\Facades\Route;

// API_SPEC.md §1.2/§1.3: every /api/v1 failure uses the error envelope with a stable code.

it('wraps errors in the envelope with a request id matching the response header', function () {
    $response = $this->withHeader('Accept-Language', 'en')
        ->getJson('/api/v1/does-not-exist')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found')
        ->assertJsonPath('error.message', 'The requested resource was not found.');

    expect($response->json('error.request_id'))->toBe($response->headers->get('X-Request-Id'))
        ->and($response->json())->toHaveKeys(['error'])->not->toHaveKey('data');
});

it('localizes error messages but keeps codes stable', function () {
    $this->withHeader('Accept-Language', 'ar')
        ->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated')
        ->assertJsonPath('error.message', 'سجّل الدخول للمتابعة.');
});

it('never leaks exception details', function () {
    config(['app.debug' => true]);
    Route::middleware('web')->get('/api/v1/__boom', fn () => throw new RuntimeException('secret detail SQLSTATE'));

    $response = $this->getJson('/api/v1/__boom')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'server_error');

    expect($response->getContent())->not->toContain('secret detail')
        ->not->toContain('RuntimeException')
        ->not->toContain('trace');
});

it('maps method mismatches without exposing internals', function () {
    $this->deleteJson('/api/v1/auth/login')
        ->assertStatus(405)
        ->assertJsonPath('error.code', 'method_not_allowed');
});
