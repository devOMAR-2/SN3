<?php

namespace App\Providers;

use App\Models\Team;
use App\Models\User;
use App\Support\Tenancy\AccessibleTeams;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Tenancy foundations (FND-005, ADR-017).
 *
 * `{team}` route parameters resolve only to teams the signed-in user belongs to. Bindings run
 * after authentication (SubstituteBindings sorts after Authenticate in the middleware priority),
 * so a guest never reaches the lookup; the exception below is a guard, not a code path.
 */
class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AccessibleTeams::class);
    }

    public function boot(): void
    {
        Route::bind('team', function (string $value): Team {
            $user = request()->user();

            if (! $user instanceof User) {
                throw new AuthenticationException;
            }

            return $this->app->make(AccessibleTeams::class)->findOrFail($user, $value);
        });
    }
}
