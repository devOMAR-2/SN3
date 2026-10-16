<?php

use Inertia\Testing\AssertableInertia as Assert;

it('renders the welcome page through Inertia', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Welcome'));
});

it('sets the document language and direction from the application locale', function (string $locale, string $dir) {
    app()->setLocale($locale);

    $this->get('/')
        ->assertOk()
        ->assertSee('<html lang="'.$locale.'" dir="'.$dir.'">', escape: false);
})->with([
    'arabic' => ['ar', 'rtl'],
    'english' => ['en', 'ltr'],
]);
