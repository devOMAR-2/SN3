<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Support\Tenancy\AccessibleProjects;
use App\Support\Tenancy\AccessibleTasks;
use App\Support\Tenancy\AccessibleTeams;
use App\Support\Tenancy\RecoverableResources;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Route as RouteDefinition;
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
        $this->app->singleton(AccessibleTasks::class);
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

        // Browser page routes (API_SPEC.md §1.1; CORE-006, ADR-026): `/t/{teamSlug}` and
        // `/t/{teamSlug}/p/{projectKey}` go through the same resolvers, so a hidden team or project is the
        // same 404 in the UI as in the API.
        Route::bind('teamSlug', function (string $value): Team {
            return $this->app->make(AccessibleTeams::class)->findBySlugOrFail($this->user(), $value);
        });

        Route::bind('projectKey', function (string $value, RouteDefinition $route): Project {
            $team = $route->parameter('teamSlug');

            if (! $team instanceof Team) {
                throw (new ModelNotFoundException)->setModel(Project::class, [$value]);
            }

            return $this->app->make(AccessibleProjects::class)->findByKeyOrFail($team, $this->user(), $value);
        });

        // `{task}` resolves only to live tasks of projects the user can see (CORE-005, ADR-023 §3);
        // `{trashedTask}` (POST /tasks/{id}/restore) only to trashed ones the user may restore.
        Route::bind('task', function (string $value): Task {
            return $this->app->make(AccessibleTasks::class)->findOrFail($this->user(), $value);
        });

        Route::bind('trashedTask', function (string $value): Task {
            return $this->app->make(AccessibleTasks::class)->findTrashedOrFail($this->user(), $value);
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
