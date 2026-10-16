<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\Tenancy\AccessibleProjects;
use App\Support\Tenancy\AccessibleTeams;
use App\Support\Tenancy\RecoverableResources;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Tenancy foundations (FND-005, ADR-017; projects CORE-003, ADR-020).
 *
 * `{team}` route parameters resolve only to teams the signed-in user belongs to, and `{project}`
 * only to projects they can see (AccessibleProjects). Anything else is a 404. Bindings run
 * after authentication (SubstituteBindings sorts after Authenticate in the middleware priority),
 * so a guest never reaches the lookup; the exception below is a guard, not a code path.
 */
class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AccessibleTeams::class);
        $this->app->singleton(AccessibleProjects::class);
        $this->app->singleton(RecoverableResources::class);
    }

    public function boot(): void
    {
        Route::bind('team', function (string $value): Team {
            return $this->app->make(AccessibleTeams::class)->findOrFail($this->user(), $value);
        });

        // `{project}` resolves only to projects the user can see (DECIDED-003): a private project
        // is a 404 for everyone but its explicit members and the Team Owner (ADR-020).
        Route::bind('project', function (string $value): Project {
            return $this->app->make(AccessibleProjects::class)->findOrFail($this->user(), $value);
        });

        // Only `POST …/restore-deleted` reaches the trash (CORE-010, ADR-022 §4): a trashed team for its
        // Owner, a trashed project for those who could see it and may recover it, within 30 days.
        Route::bind('trashedTeam', function (string $value): Team {
            return $this->app->make(RecoverableResources::class)->team($this->user(), $value);
        });

        Route::bind('trashedProject', function (string $value): Project {
            return $this->app->make(RecoverableResources::class)->project($this->user(), $value);
        });
    }

    private function user(): User
    {
        $user = request()->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
