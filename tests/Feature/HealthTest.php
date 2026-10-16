<?php

use Illuminate\Support\Facades\DB;

it('reports liveness without touching dependencies', function () {
    config(['database.connections.pgsql.port' => 1]);
    DB::purge('pgsql');

    $this->getJson('/health/live')
        ->assertOk()
        ->assertExactJson(['status' => 'ok'])
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('reports ready when the database is reachable', function () {
    $this->getJson('/health/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ready']);
});

it('reports unavailable without leaking details when the database is unreachable', function () {
    config([
        'database.connections.pgsql.host' => '127.0.0.1',
        'database.connections.pgsql.port' => 1,
    ]);
    DB::purge('pgsql');

    $response = $this->getJson('/health/ready')
        ->assertStatus(503)
        ->assertExactJson(['status' => 'unavailable']);

    expect($response->getContent())
        ->not->toContain('127.0.0.1')
        ->not->toContain('pgsql')
        ->not->toContain('SQLSTATE');
});

it('does not start a session or set cookies on probes', function (string $uri) {
    $response = $this->get($uri);

    expect($response->headers->getCookies())->toBeEmpty();
})->with(['/health/live', '/health/ready']);

it('no longer exposes the framework default /up route', function () {
    $this->get('/up')->assertNotFound();
});
