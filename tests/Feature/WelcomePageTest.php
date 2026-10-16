<?php

use Inertia\Testing\AssertableInertia as Assert;

it('renders the welcome page through Inertia', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Welcome'));
});

// TEST-FR-I18N-002: the document language and direction follow the resolved locale.
it('sets the document language and direction from the resolved locale', function (string $locale, string $dir) {
    $this->withSession(['locale' => $locale])
        ->get('/')
        ->assertOk()
        ->assertSee('<html lang="'.$locale.'" dir="'.$dir.'">', escape: false)
        ->assertInertia(fn (Assert $page) => $page->where('locale', $locale)->where('dir', $dir));
})->with([
    'arabic' => ['ar', 'rtl'],
    'english' => ['en', 'ltr'],
]);

it('applies the saved theme before first paint without external requests', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain("window.localStorage.getItem('sn3.theme')")
        ->toContain('prefers-color-scheme: dark')
        ->toContain('<meta name="color-scheme" content="light dark">')
        ->not->toMatch('#<(?:script|link)[^>]+(?:src|href)="https?://#i');

    // The theme script must run before the stylesheet/bundle tags.
    expect(strpos($html, 'sn3.theme'))->toBeLessThan(strpos($html, '<title'));
});
