<?php

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// FND-004 acceptance: invalid and expired sessions, using the real database session driver.

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lifetime' => 120]);
    $this->user = User::factory()->create();
});

function storedSessionFor(User $user, int $lastActivity): string
{
    $id = Str::random(40);

    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'pest',
        'payload' => base64_encode(serialize([
            '_token' => Str::random(40),
            'login_web_'.sha1(SessionGuard::class) => $user->id,
        ])),
        'last_activity' => $lastActivity,
    ]);

    return $id;
}

it('authenticates a request carrying a live stored session', function () {
    $id = storedSessionFor($this->user, now()->getTimestamp());

    $this->withCredentials()
        ->withCookie(config('session.cookie'), $id)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $this->user->id);
});

it('treats an expired session as signed out', function () {
    $id = storedSessionFor($this->user, now()->subMinutes(121)->getTimestamp());

    $this->withCredentials()
        ->withCookie(config('session.cookie'), $id)
        ->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');

    $this->withCookie(config('session.cookie'), $id)
        ->get('/settings')
        ->assertRedirect('/login');
});

it('treats an unknown or tampered session id as signed out', function (string $id) {
    $this->withCredentials()
        ->withCookie(config('session.cookie'), $id)
        ->getJson('/api/v1/me')
        ->assertStatus(401);
})->with([
    'random id' => [Str::random(40)],
    'malformed id' => ['../../etc/passwd'],
]);

it('deletes the stored session when the user signs out', function () {
    $id = storedSessionFor($this->user, now()->getTimestamp());

    $this->withCredentials()
        ->withCookie(config('session.cookie'), $id)
        ->postJson('/api/v1/auth/logout')
        ->assertNoContent();

    expect(DB::table('sessions')->where('id', $id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $this->user->id)->exists())->toBeFalse();
});
