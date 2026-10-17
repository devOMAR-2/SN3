<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Pages\Abilities;
use App\Http\Pages\Workspace;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\ProjectSettingsResource;
use App\Http\Resources\ProjectStatusResource;
use App\Http\Resources\TeamResource;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Team;
use App\Models\User;
use App\Support\Tenancy\RecoverableResources;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project pages (API_SPEC.md §1.1 `/t/{team_slug}/projects`, `/t/{team_slug}/p/{project_key}`;
 * CORE-006, ADR-026). `{teamSlug}` resolves through AccessibleTeams and `{projectKey}` through
 * AccessibleProjects inside that team, so a private project is the same 404 here as in the API for
 * everyone but its members and the Team Owner. Changes go to /api/v1.
 */
class ProjectPageController extends Controller
{
    /** `/t/{team_slug}/projects`: the team's projects (GET /api/v1/teams/{team}/projects) and trash. */
    public function index(Request $request, Team $teamSlug, RecoverableResources $recoverable): Response
    {
        $user = $this->user($request);

        Gate::forUser($user)->authorize('view', $teamSlug);

        return Inertia::render('Projects/Index', [
            'team' => TeamResource::make($teamSlug, $user),
            'can' => Abilities::team($user, $teamSlug),
            'lock' => Abilities::lock($teamSlug),
            'deletedProjects' => array_map(Workspace::trashedProject(...), $recoverable->projectsInTeam($teamSlug, $user)),
        ]);
    }

    /** `/t/{team_slug}/projects/new`: create a project (Owner or Admin, as POST /teams/{team}/projects). */
    public function create(Request $request, Team $teamSlug): Response
    {
        $user = $this->user($request);

        Gate::forUser($user)->authorize('createProject', $teamSlug);

        return Inertia::render('Projects/Create', [
            'team' => TeamResource::make($teamSlug, $user),
            'can' => Abilities::team($user, $teamSlug),
            'lock' => Abilities::lock($teamSlug),
            'onboarding' => $request->boolean('onboarding'),
        ]);
    }

    /** `/t/{team_slug}/p/{project_key}`: the project with its (deliberately simple) task list. */
    public function show(Request $request, Team $teamSlug, Project $projectKey): Response
    {
        return Inertia::render('Projects/Show', $this->props($request, $teamSlug, $projectKey));
    }

    /**
     * `/t/{team_slug}/p/{project_key}/settings`: general, sprints, workflow, members and the danger
     * zone. Only for someone who may change at least one of them; anyone else who sees the project
     * gets 403.
     */
    public function settings(Request $request, Team $teamSlug, Project $projectKey): Response
    {
        abort_unless(Abilities::viewsProjectSettings($this->user($request), $projectKey), 403);

        return Inertia::render('Projects/Settings', [
            ...$this->props($request, $teamSlug, $projectKey),
            'settings' => ProjectSettingsResource::make($projectKey),
            'workflowVersion' => $projectKey->workflow_version,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function props(Request $request, Team $team, Project $project): array
    {
        $user = $this->user($request);

        Gate::forUser($user)->authorize('view', $project);

        // A project holds at most 50 statuses (ADR-021 §4), so the whole workflow is one bounded list.
        $statuses = $project->statuses()->orderBy('position')->orderBy('id')->get();

        return [
            'team' => TeamResource::make($team, $user),
            'project' => ProjectResource::make($project, $user),
            'statuses' => $statuses->map(fn (ProjectStatus $status): array => ProjectStatusResource::make($status))->values()->all(),
            'can' => Abilities::project($user, $project),
            'teamCan' => Abilities::team($user, $team),
            'lock' => Abilities::lock($team, $project),
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
