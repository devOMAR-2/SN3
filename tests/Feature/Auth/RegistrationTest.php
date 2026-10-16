<?php

use App\Models\User;
use App\Notifications\Auth\AccountAlreadyExistsNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// TEST-FR-AUTH-001: registration with email and password; operator-configurable availability.

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
});

function registrationPayload(array $overrides = []): array
{
    return [
        'name' => 'Noura Al-Harbi',
        'email' => 'Noura@Example.com ',
        'password' => 'correct horse battery',
        ...$overrides,
    ];
}

it('creates an unverified account with normalised email, preferences and a queued verification email', function () {
    $this->withHeader('Accept-Language', 'en')
        ->postJson('/api/v1/auth/register', registrationPayload())
        ->assertStatus(202)
        ->assertJsonPath('data', ['status' => 'verification_sent'])
        ->assertJsonStructure(['data', 'meta' => ['request_id']]);

    $user = User::query()->sole();

    expect($user->email)->toBe('noura@example.com')
        ->and($user->name)->toBe('Noura Al-Harbi')
        ->and($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->locale)->toBe('en')
        ->and($user->timezone)->toBe('UTC')
        ->and($user->theme())->toBe('system')
        ->and(Str::isUuid($user->id))->toBeTrue()
        ->and(password_verify('correct horse battery', $user->password))->toBeTrue();

    Notification::assertSentTo($user, VerifyEmailNotification::class);
    $this->assertGuest();
});

it('uses UUIDv7 identifiers', function () {
    $this->postJson('/api/v1/auth/register', registrationPayload())->assertStatus(202);

    expect(User::query()->sole()->id[14])->toBe('7');
});

it('returns the same response for an existing email and notifies that owner instead', function () {
    $owner = User::factory()->create(['email' => 'noura@example.com']);

    $response = $this->postJson('/api/v1/auth/register', registrationPayload(['name' => 'Someone else']))
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'verification_sent');

    expect(User::query()->count())->toBe(1)
        ->and($owner->fresh()->name)->toBe($owner->name)
        ->and($response->json())->not->toHaveKey('error');

    Notification::assertSentTo($owner, AccountAlreadyExistsNotification::class);
    Notification::assertNotSentTo($owner, VerifyEmailNotification::class);
    $this->assertGuest();
});

it('does not notify a disabled owner', function () {
    $owner = User::factory()->disabled()->create(['email' => 'noura@example.com']);

    $this->postJson('/api/v1/auth/register', registrationPayload())->assertStatus(202);

    Notification::assertNothingSentTo($owner);
});

it('validates input with stable codes', function (array $payload, string $field, string $code) {
    $this->postJson('/api/v1/auth/register', registrationPayload($payload))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath("error.fields.{$field}", fn (array $codes) => in_array($code, $codes, true));

    expect(User::query()->count())->toBe(0);
})->with([
    'missing name' => [['name' => ''], 'name', 'required'],
    'name too long' => [['name' => str_repeat('ن', 101)], 'name', 'max'],
    'invalid email' => [['email' => 'not-an-email'], 'email', 'email'],
    'password too short' => [['password' => 'elevenchars'], 'password', 'min'],
    'password too long' => [['password' => str_repeat('a', 129)], 'password', 'max'],
]);

it('accepts the password length boundaries', function (int $length) {
    $this->postJson('/api/v1/auth/register', registrationPayload(['password' => str_repeat('a', $length)]))
        ->assertStatus(202);
})->with([12, 128]);

it('can be closed by the operator', function () {
    config(['sn3.registration.enabled' => false]);

    $this->postJson('/api/v1/auth/register', registrationPayload())
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    $this->get('/register')->assertNotFound();
    expect(User::query()->count())->toBe(0);
});

it('is throttled per IP with rate_limited and Retry-After', function () {
    config(['sn3.rate_limits.register_per_minute' => 2]);

    foreach (range(1, 2) as $i) {
        $this->postJson('/api/v1/auth/register', registrationPayload(['email' => "u{$i}@example.com"]))->assertStatus(202);
    }

    $this->postJson('/api/v1/auth/register', registrationPayload(['email' => 'u3@example.com']))
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertHeader('Retry-After');
});

it('requires a CSRF token from browser sessions', function () {
    enforceCsrf();

    $this->postJson('/api/v1/auth/register', registrationPayload())
        ->assertStatus(419)
        ->assertJsonPath('error.code', 'csrf_invalid');

    expect(User::query()->count())->toBe(0);
});
