<?php

use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

function verificationUrl(User $user, ?string $hash = null, int $minutes = 60): string
{
    return URL::temporarySignedRoute('api.auth.verification.verify', now()->addMinutes($minutes), [
        'id' => $user->id,
        'hash' => $hash ?? hash('sha256', $user->email),
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->unverified()->create();
});

it('verifies from the emailed link without an active session and redirects browsers to sign-in', function () {
    Event::fake([Verified::class]);

    $this->get(verificationUrl($this->user))
        ->assertRedirect('/login')
        ->assertSessionHas('status', 'email_verified');

    expect($this->user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertDispatchedTimes(Verified::class, 1);
});

it('answers JSON clients with the envelope and is idempotent', function () {
    $url = verificationUrl($this->user);

    $this->getJson($url)->assertOk()->assertJsonPath('data.status', 'verified');
    $this->getJson($url)->assertOk()->assertJsonPath('data.status', 'verified');
});

function badVerificationUrl(string $variant, User $user): string
{
    return match ($variant) {
        'tampered signature' => verificationUrl($user).'0',
        'wrong email hash' => verificationUrl($user, hash('sha256', 'someone@else.test')),
        'other user id' => str_replace($user->id, User::factory()->unverified()->create()->id, verificationUrl($user)),
        'expired' => verificationUrl($user, minutes: -1),
        'not a uuid' => URL::temporarySignedRoute('api.auth.verification.verify', now()->addHour(), ['id' => '1', 'hash' => 'x']),
    };
}

it('rejects tampered, mismatched and expired links the same way', function (string $variant) {
    $this->getJson(badVerificationUrl($variant, $this->user))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    $this->get(badVerificationUrl($variant, $this->user))
        ->assertRedirect('/login')
        ->assertSessionHas('status', 'verification_link_invalid');

    expect($this->user->fresh()->hasVerifiedEmail())->toBeFalse();
})->with(['tampered signature', 'wrong email hash', 'other user id', 'expired', 'not a uuid']);

it('queues the verification email with a signed link that works', function () {
    Notification::fake();
    $this->actingAs($this->user);

    $this->postJson('/api/v1/auth/email/resend')
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'verification_sent');

    Notification::assertSentTo($this->user, VerifyEmailNotification::class, function (VerifyEmailNotification $notification) {
        $url = $notification->toMail($this->user)->actionUrl;
        auth()->logout();
        $this->getJson($url)->assertOk();

        return true;
    });

    expect($this->user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('does not resend to an already verified user', function () {
    Notification::fake();
    $verified = User::factory()->create();

    $this->actingAs($verified)
        ->postJson('/api/v1/auth/email/resend')
        ->assertOk()
        ->assertJsonPath('data.status', 'already_verified');

    Notification::assertNothingSent();
});

it('requires authentication and is throttled for resends', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/email/resend')->assertStatus(401);

    config(['sn3.rate_limits.verification_per_minute' => 1]);
    $this->actingAs($this->user);
    $this->postJson('/api/v1/auth/email/resend')->assertStatus(202);
    $this->postJson('/api/v1/auth/email/resend')
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertHeader('Retry-After');
});
