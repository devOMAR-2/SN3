<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Invitations\Actions\CreateInvitation;
use App\Domain\Invitations\Actions\RevokeInvitation;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Pagination\CursorPagination;
use App\Http\Requests\Invitations\InvitationActionRequest;
use App\Http\Requests\Invitations\StoreInvitationRequest;
use App\Http\Resources\TeamInvitationResource;
use App\Http\Responses\ApiResponse;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitationNotification;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Team invitations (API_SPEC.md §3.1; CORE-002, ADR-025): create, list metadata, revoke. Owner or
 * Admin; other members get 403, everyone else 404 (`{team}` binding). `{invitation}` resolves only
 * among this team's invitations (else 404).
 */
class TeamInvitationController extends Controller
{
    public function index(Request $request, Team $team): JsonResponse
    {
        Gate::authorize('invite', $team);

        $query = TeamInvitation::query()
            ->select(TeamInvitation::COLUMNS)
            ->where('team_id', $team->id)
            ->with('inviter:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return CursorPagination::respond($request, $query, fn (TeamInvitation $invitation): array => TeamInvitationResource::make($invitation));
    }

    /**
     * 201 with the invitation, its raw `token` and the `url` to share. The token is shown this once:
     * an Idempotency-Key replay returns the same invitation with `token` and `url` as null, because
     * the stored replay never contains them (ADR-025 §2).
     */
    public function store(StoreInvitationRequest $request, Team $team, CreateInvitation $createInvitation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $role = $request->role();

        Gate::authorize('inviteAs', [$team, $role]);

        ['invitation' => $invitation, 'token' => $token] = $createInvitation->handle($team, $user, $request->type(), $role, $request->email());

        // On the base request the middleware holds (the form request is a copy of it).
        request()->attributes->set(EnforceIdempotency::REDACT_ATTRIBUTE, ['data.token', 'data.url']);

        return ApiResponse::data([
            ...TeamInvitationResource::make($invitation),
            'token' => $token,
            'url' => TeamInvitationNotification::url($token),
        ], 201);
    }

    public function destroy(InvitationActionRequest $request, Team $team, string $invitation, RevokeInvitation $revokeInvitation): Response
    {
        /** @var User $user */
        $user = $request->user();

        $revokeInvitation->handle($team, $user, self::invitation($team, $invitation));

        return response()->noContent();
    }

    /**
     * @throws ModelNotFoundException<TeamInvitation>
     */
    private static function invitation(Team $team, string $id): TeamInvitation
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException)->setModel(TeamInvitation::class);
        }

        return TeamInvitation::query()->select(TeamInvitation::COLUMNS)->where('team_id', $team->id)->findOrFail($id);
    }
}
