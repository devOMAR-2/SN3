<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Teams\Actions\TransferOwnership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\TransferOwnershipRequest;
use App\Http\Resources\TeamResource;
use App\Http\Responses\ApiResponse;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/teams/{team}/ownership-transfer (API_SPEC.md §3, FR-TEAM-005). Current Owner only;
 * the new Owner must already be a member. The previous Owner becomes Admin.
 */
class TeamOwnershipController extends Controller
{
    public function __invoke(TransferOwnershipRequest $request, Team $team, TransferOwnership $transfer): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $updated = $transfer->handle($team, $user, $request->string('new_owner_user_id')->lower()->toString());

        return ApiResponse::data(TeamResource::make($updated, $user));
    }
}
