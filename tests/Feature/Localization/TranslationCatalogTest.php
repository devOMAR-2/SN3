<?php

use Illuminate\Support\Arr;

// TEST-FR-I18N-001: every server-side English line has an Arabic counterpart with the same placeholders.

/**
 * @return array<string, mixed>
 */
function translationLines(string $locale, string $file): array
{
    return Arr::dot(require lang_path("{$locale}/{$file}"));
}

// Datasets are built before the application boots, so lang_path() is not available here.
dataset('english catalogs', fn () => array_map(
    'basename',
    glob(dirname(__DIR__, 3).'/lang/en/*.php') ?: [],
));

it('has an Arabic catalog for every English catalog', function (string $file) {
    expect(lang_path("ar/{$file}"))->toBeFile();
})->with('english catalogs');

it('translates every English key into Arabic with the same placeholders', function (string $file) {
    $english = translationLines('en', $file);
    $arabic = translationLines('ar', $file);

    foreach ($english as $key => $line) {
        // Attribute names are optional per locale; Arabic may name more fields than English.
        if (str_starts_with($key, 'attributes.')) {
            continue;
        }

        expect($arabic)->toHaveKey($key);

        preg_match_all('/:[a-z_]+/', (string) $line, $expected);
        preg_match_all('/:[a-z_]+/', (string) $arabic[$key], $actual);

        expect(array_unique($actual[0]))->toEqualCanonicalizing(array_unique($expected[0]), "Placeholders differ for {$file}:{$key}");
    }

    $extra = array_filter(
        array_diff(array_keys($arabic), array_keys($english)),
        fn (string $key) => ! str_starts_with($key, 'attributes.'),
    );

    expect($extra)->toBe([]);
})->with('english catalogs');

it('names every Arabic validation attribute in Arabic', function () {
    foreach (trans('validation.attributes', locale: 'ar') as $attribute => $label) {
        expect($label)->toMatch('/\p{Arabic}/u', "validation.attributes.{$attribute} is not Arabic");
    }
});
