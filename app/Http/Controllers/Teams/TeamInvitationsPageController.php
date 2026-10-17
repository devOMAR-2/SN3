<?php

namespace App\Http\Controllers\Teams;

use App\Domain\Teams\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/teams/{team}/invitations` (CORE-002, ADR-025 §8): the team's invitations panel. `{team}` resolves
 * only to the caller's own teams (404 otherwise); Members and Viewers get 403, like the API.
 */
class TeamInvitationsPageController extends Controller
{
    public function __invoke(Request $request, Team $team): Response
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('invite', $team);

        return Inertia::render('Teams/Invitations', [
            'team' => ['id' => $team->id, 'name' => $team->name],
            'can' => ['invite_admin' => Gate::forUser($user)->allows('inviteAs', [$team, TeamRole::Admin])],
        ]);
    }
}
