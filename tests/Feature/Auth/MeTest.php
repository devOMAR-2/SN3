<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

// TEST-FR-AUTH-002: manage profile, locale and timezone (GET/PATCH /me).

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Sara', 'locale' => 'ar', 'timezone' => 'UTC']);
});

it('requires authentication', function (string $method) {
    $this->json($method, '/api/v1/me', ['name' => 'X'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated')
        ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
})->with(['GET', 'PATCH']);

it('returns the caller profile with defaults', function () {
    $this->actingAs($this->user)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $this->user->id)
        ->assertJsonPath('data.locale', 'ar')
        ->assertJsonPath('data.timezone', 'UTC')
        ->assertJsonPath('data.theme', 'system')
        ->assertJsonPath('data.email_verified', true)
        ->assertJsonMissingPath('data.password');
});

it('partially updates name, locale, timezone and theme', function () {
    $this->actingAs($this->user)
        ->patchJson('/api/v1/me', ['name' => '  سارة  ', 'locale' => 'en', 'timezone' => 'Asia/Riyadh', 'theme' => 'dark'])
        ->assertOk()
        ->assertJsonPath('data.name', 'سارة')
        ->assertJsonPath('data.locale', 'en')
        ->assertJsonPath('data.timezone', 'Asia/Riyadh')
        ->assertJsonPath('data.theme', 'dark');

    $this->actingAs($this->user)
        ->patchJson('/api/v1/me', ['theme' => 'light'])
        ->assertOk()
        ->assertJsonPath('data.name', 'سارة')
        ->assertJsonPath('data.theme', 'light');

    expect($this->user->fresh()->preferences()->count())->toBe(1);
});

it('applies the saved locale and theme to the next page', function () {
    $this->actingAs($this->user)->patchJson('/api/v1/me', ['locale' => 'en', 'theme' => 'dark'])->assertOk();

    $this->actingAs($this->user->fresh())
        ->withHeader('Accept-Language', 'ar')
        ->get('/settings')
        ->assertOk()
        ->assertSee('<html lang="en" dir="ltr" data-theme-preference="dark">', escape: false)
        ->assertInertia(fn (Assert $page) => $page->component('Settings/Profile')
            ->where('locale', 'en')
            ->where('auth.user.theme', 'dark'));
});

it('rejects invalid values and unknown fields with stable codes', function (array $payload, string $field, string $code) {
    $this->actingAs($this->user)
        ->patchJson('/api/v1/me', $payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath("error.fields.{$field}", [$code]);

    expect($this->user->fresh()->only(['name', 'locale', 'timezone']))
        ->toBe(['name' => 'Sara', 'locale' => 'ar', 'timezone' => 'UTC']);
})->with([
    'empty name' => [['name' => ' '], 'name', 'required'],
    'long name' => [['name' => str_repeat('a', 101)], 'name', 'max'],
    'unsupported locale' => [['locale' => 'fr'], 'locale', 'in'],
    'invalid timezone' => [['timezone' => 'Mars/Olympus'], 'timezone', 'timezone'],
    'invalid theme' => [['theme' => 'neon'], 'theme', 'in'],
    'email is not editable here' => [['email' => 'new@example.com'], 'email', 'unknown_field'],
    'unknown field' => [['is_admin' => true], 'is_admin', 'unknown_field'],
]);

it('saves the language switch of a signed-in user to the profile', function () {
    $this->actingAs($this->user)
        ->from('/settings')
        ->put('/locale', ['locale' => 'en'])
        ->assertRedirect('/settings');

    expect($this->user->fresh()->locale)->toBe('en');
});
