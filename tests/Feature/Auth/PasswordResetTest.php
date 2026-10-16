<?php

use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

// TEST-FR-AUTH-002 (password reset).

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    $this->user = User::factory()->create(['email' => 'omar@example.com']);
});

it('answers 202 with the same body whether or not the email exists', function (string $email) {
    $this->postJson('/api/v1/auth/password/forgot', ['email' => $email])
        ->assertStatus(202)
        ->assertJsonPath('data', ['status' => 'reset_link_sent']);
})->with(['omar@example.com', 'Omar@Example.com', 'nobody@example.com']);

it('queues a localized reset email pointing at the reset page', function () {
    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'OMAR@example.com'])->assertStatus(202);

    Notification::assertSentTo($this->user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) {
        $mail = $notification->toMail($this->user);

        // Token and email only in the fragment, which browsers never send to the server.
        return $mail->actionUrl === url('/reset-password').'#token='.rawurlencode($notification->token).'&email=omar%40example.com';
    });
});

it('sends nothing for unknown or disabled accounts', function () {
    $disabled = User::factory()->disabled()->create(['email' => 'off@example.com']);

    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'off@example.com'])->assertStatus(202);
    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.com'])->assertStatus(202);

    Notification::assertNothingSentTo($disabled);
    Notification::assertCount(0);
});

it('resets the password, rotates the remember token and revokes every stored session', function () {
    $token = Password::createToken($this->user);
    $rememberToken = $this->user->remember_token;
    DB::table('sessions')->insert([
        'id' => Str::random(40), 'user_id' => $this->user->id, 'payload' => '', 'last_activity' => now()->getTimestamp(),
    ]);

    $this->postJson('/api/v1/auth/password/reset', [
        'token' => $token,
        'email' => 'omar@example.com',
        'password' => 'a brand new passphrase',
    ])->assertOk()->assertJsonPath('data.status', 'password_reset');

    $user = $this->user->fresh();
    expect(Hash::check('a brand new passphrase', $user->password))->toBeTrue()
        ->and($user->remember_token)->not->toBe($rememberToken)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);

    // A reset token is single-use.
    $this->postJson('/api/v1/auth/password/reset', [
        'token' => $token,
        'email' => 'omar@example.com',
        'password' => 'yet another passphrase',
    ])->assertStatus(422)->assertJsonPath('error.fields.token', ['invalid_token']);
});

it('returns the same invalid_token error for a bad token and an unknown email', function (string $email, string $token) {
    Password::createToken($this->user);

    $this->postJson('/api/v1/auth/password/reset', [
        'token' => $token,
        'email' => $email,
        'password' => 'a brand new passphrase',
    ])->assertStatus(422)->assertJsonPath('error.fields', ['token' => ['invalid_token']]);

    expect(Hash::check('password', $this->user->fresh()->password))->toBeTrue();
})->with([
    'bad token' => ['omar@example.com', 'not-the-token'],
    'unknown email' => ['nobody@example.com', 'not-the-token'],
]);

it('rejects an expired token', function () {
    $token = Password::createToken($this->user);
    $this->travel(61)->minutes();

    $this->postJson('/api/v1/auth/password/reset', [
        'token' => $token,
        'email' => 'omar@example.com',
        'password' => 'a brand new passphrase',
    ])->assertStatus(422)->assertJsonPath('error.fields.token', ['invalid_token']);
});

it('enforces the password policy on reset', function () {
    $this->postJson('/api/v1/auth/password/reset', [
        'token' => Password::createToken($this->user),
        'email' => 'omar@example.com',
        'password' => 'short',
    ])->assertStatus(422)->assertJsonPath('error.fields.password', ['min']);
});

it('throttles reset requests per IP', function () {
    config(['sn3.rate_limits.password_reset_per_minute' => 2]);

    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'a@example.com'])->assertStatus(202);
    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'b@example.com'])->assertStatus(202);
    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'c@example.com'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertHeader('Retry-After');
});
