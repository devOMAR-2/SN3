<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Invitations\Actions\AcceptInvitation;
use App\Domain\Invitations\InvitationToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invitations\InvitationActionRequest;
use App\Http\Resources\TeamInvitationResource;
use App\Http\Resources\TeamResource;
use App\Http\Responses\ApiResponse;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The token side of invitations (API_SPEC.md §3.1; ADR-025 §4). The token is the credential: an
 * unknown token, or one whose team is gone or in the trash, is 404; a revoked, used-up or expired
 * one is 410 `expired`.
 */
class InvitationTokenController extends Controller
{
    /** GET /invitations/{token}/preview: team name and role, for any holder of a valid token. */
    public function preview(string $token): JsonResponse
    {
        $invitation = InvitationToken::isWellFormed($token)
            ? TeamInvitation::whereTokenHash(InvitationToken::hash($token))->first()
            : null;
        $team = $invitation === null ? null : Team::query()->find($invitation->team_id);

        if ($invitation === null || $team === null) {
            throw (new ModelNotFoundException)->setModel(TeamInvitation::class);
        }

        if (! $invitation->isRedeemable()) {
            throw new HttpException(410);
        }

        return ApiResponse::data(TeamInvitationResource::preview($invitation, $team->name), headers: ['Cache-Control' => 'no-store']);
    }

    /** POST /invitations/{token}/accept: 201 with the team when joined, 200 when already a member. */
    public function accept(InvitationActionRequest $request, string $token, AcceptInvitation $acceptInvitation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        ['team' => $team, 'created' => $created] = $acceptInvitation->handle($token, $user);

        return ApiResponse::data(TeamResource::make($team->unsetRelation('memberships'), $user), $created ? 201 : 200);
    }
}
