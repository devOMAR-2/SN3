<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Pages\Abilities;
use App\Http\Pages\Workspace;
use App\Http\Resources\TeamResource;
use App\Models\Team;
use App\Models\User;
use App\Support\Tenancy\RecoverableResources;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Team pages (API_SPEC.md §1.1 browser routes; CORE-006, ADR-026). Read-only: `{teamSlug}` resolves
 * through AccessibleTeams, so another team's page is the same 404 as an unknown one, and every change
 * these pages offer is a request to the /api/v1 endpoints, which authorise it again.
 */
class TeamPageController extends Controller
{
    /** `/teams`: the user's teams (listed by the page from GET /api/v1/teams) and their trash. */
    public function index(Request $request, RecoverableResources $recoverable): Response
    {
        $user = $this->user($request);

        return Inertia::render('Teams/Index', [
            'deletedTeams' => array_map(Workspace::trashedTeam(...), $recoverable->teams($user)),
            'can' => ['create_team' => $user->hasVerifiedEmail()],
        ]);
    }

    /** `/teams/new`: the team creation wizard (name, then the first project). */
    public function create(Request $request): Response
    {
        return Inertia::render('Teams/Create', [
            'can' => ['create_team' => $this->user($request)->hasVerifiedEmail()],
        ]);
    }

    /** `/t/{team_slug}`: team overview. */
    public function show(Request $request, Team $teamSlug): Response
    {
        return Inertia::render('Teams/Show', $this->props($request, $teamSlug));
    }

    /** `/t/{team_slug}/members`: the members list (GET /api/v1/teams/{team}/members) and its actions. */
    public function members(Request $request, Team $teamSlug): Response
    {
        return Inertia::render('Teams/Members', $this->props($request, $teamSlug));
    }

    /**
     * `/t/{team_slug}/settings`: general settings and the danger zone. Only for someone who may change
     * at least one of them (Owner or Admin); everyone else who can see the team gets 403.
     */
    public function settings(Request $request, Team $teamSlug): Response
    {
        abort_unless(Abilities::viewsTeamSettings($this->user($request), $teamSlug), 403);

        return Inertia::render('Teams/Settings', $this->props($request, $teamSlug));
    }

    /**
     * @return array<string, mixed>
     */
    private function props(Request $request, Team $team): array
    {
        $user = $this->user($request);

        Gate::forUser($user)->authorize('view', $team);

        return [
            'team' => TeamResource::make($team, $user),
            'can' => Abilities::team($user, $team),
            'lock' => Abilities::lock($team),
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
