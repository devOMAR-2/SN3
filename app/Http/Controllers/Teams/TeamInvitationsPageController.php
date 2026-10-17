<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * `/teams/{team}/invitations` (CORE-002, ADR-025 §8): kept as a stable link to the team's invitations,
 * which since CORE-006 live in the members page (`/t/{team_slug}/members`, ADR-026 §6). `{team}`
 * resolves only to the caller's own teams (404 otherwise); Members and Viewers get 403, like the API.
 */
class TeamInvitationsPageController extends Controller
{
    public function __invoke(Team $team): RedirectResponse
    {
        Gate::authorize('invite', $team);

        return redirect()->route('teams.members', ['teamSlug' => $team->slug]);
    }
}
