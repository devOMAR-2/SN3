<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// TEST-FR-I18N-001 / TEST-FR-I18N-003: the interface language is resolved per request on the server.

it('defaults guests without a browser preference to Arabic', function () {
    // The test client sends `Accept-Language: en-us,en;q=0.5` unless told otherwise.
    $this->withHeader('Accept-Language', '')
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'ar')->where('dir', 'rtl'));

    expect(app()->getLocale())->toBe('ar');
});

it('uses the first supported Accept-Language entry', function (string $header, string $locale, string $dir) {
    $this->withHeader('Accept-Language', $header)
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locale', $locale)->where('dir', $dir));
})->with([
    'english with region' => ['en-US,en;q=0.9', 'en', 'ltr'],
    'arabic with region' => ['ar-SA,ar;q=0.9,en;q=0.8', 'ar', 'rtl'],
    'unsupported first, english later' => ['fr-FR,fr;q=0.9,en;q=0.5', 'en', 'ltr'],
    'quality order beats header order' => ['ar;q=0.4,en;q=0.8', 'en', 'ltr'],
    'only unsupported languages' => ['fr-FR,de;q=0.8', 'ar', 'rtl'],
    'english explicitly not acceptable' => ['en;q=0', 'ar', 'rtl'],
    'malformed header' => ['%%%,;;', 'ar', 'rtl'],
]);

it('prefers a guest session choice over Accept-Language', function () {
    $this->withSession(['locale' => 'en'])
        ->withHeader('Accept-Language', 'ar-SA')
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en')->where('dir', 'ltr'));
});

it('ignores an unsupported session value', function (mixed $value) {
    $this->withSession(['locale' => $value])
        ->withHeader('Accept-Language', 'en')
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
})->with([
    'unknown code' => ['fr'],
    'region tag' => ['en-US'],
    'wrong case' => ['EN'],
    'non-string' => [['en']],
]);

it("prefers the signed-in user's saved locale over the session and browser", function () {
    $user = (new User)->forceFill(['id' => 1, 'locale' => 'en']);

    $this->actingAs($user)
        ->withSession(['locale' => 'ar'])
        ->withHeader('Accept-Language', 'ar')
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en')->where('dir', 'ltr'));
});

it('falls through when the signed-in user has no valid locale', function (?string $value) {
    $user = (new User)->forceFill(['id' => 1, 'locale' => $value]);

    $this->actingAs($user)
        ->withSession(['locale' => 'en'])
        ->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
})->with([
    'no locale attribute yet' => [null],
    'unsupported value' => ['xx'],
]);

it('localizes server-side validation messages', function () {
    $this->withSession(['locale' => 'ar'])
        ->from('/')
        ->put('/locale', ['locale' => 'fr'])
        ->assertSessionHasErrors(['locale' => 'القيمة المحددة في حقل اللغة غير صالحة.']);

    $this->withSession(['locale' => 'en'])
        ->from('/')
        ->put('/locale', ['locale' => 'fr'])
        ->assertSessionHasErrors(['locale' => 'The selected language is invalid.']);
});
