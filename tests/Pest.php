<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the Laravel application. Tests that touch the database
| must opt in to RefreshDatabase explicitly and run against PostgreSQL.
|
*/

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * The framework skips CSRF verification while running tests; call this to enforce it for
 * the rest of the current test.
 */
function enforceCsrf(): void
{
    $app = app();

    $app->instance(
        ValidateCsrfToken::class,
        new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        },
    );
}
