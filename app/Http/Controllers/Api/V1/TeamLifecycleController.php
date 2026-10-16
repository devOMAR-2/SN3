<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Teams\Actions\ArchiveTeam;
use App\Domain\Teams\Actions\RestoreDeletedTeam;
use App\Domain\Teams\Actions\TrashTeam;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lifecycle\ArchiveTeamRequest;
use App\Http\Requests\Lifecycle\RestoreDeletedTeamRequest;
use App\Http\Requests\Lifecycle\TrashTeamRequest;
use App\Http\Resources\TeamResource;
use App\Http\Responses\ApiResponse;
use App\Models\Team;
use App\Models\User;
use App\Support\Tenancy\AccessibleTeams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Team archive and trash (API_SPEC.md §3, DECIDED-011, ADR-022), all Owner only:
 * `POST /teams/{team}/archive`, `POST /teams/{team}/restore` (unarchive), `DELETE /teams/{team}`
 * (30-day trash) and `POST /teams/{team}/restore-deleted`.
 *
 * `{team}` resolves through AccessibleTeams (404 for non-members and trashed teams; 403 for members
 * without the right). `{trashedTeam}` resolves through RecoverableResources: only the Owner of a
 * team trashed less than 30 days ago gets past it; everyone else gets 404.
 */
class TeamLifecycleController extends Controller
{
    public function __construct(private readonly AccessibleTeams $teams) {}

    public function archive(ArchiveTeamRequest $request, Team $team, ArchiveTeam $archive): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->respond($user, $archive->handle($team, $user, true));
    }

    public function unarchive(ArchiveTeamRequest $request, Team $team, ArchiveTeam $archive): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->respond($user, $archive->handle($team, $user, false));
    }

    public function destroy(TrashTeamRequest $request, Team $team, TrashTeam $trash): Response
    {
        /** @var User $user */
        $user = $request->user();

        $trash->handle($team, $user);

        return response()->noContent();
    }

    public function restoreDeleted(RestoreDeletedTeamRequest $request, Team $trashedTeam, RestoreDeletedTeam $restore): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->respond($user, $restore->handle($trashedTeam, $user));
    }

    private function respond(User $user, Team $team): JsonResponse
    {
        // Re-read through the normal resolver, with the caller's own membership loaded.
        return ApiResponse::data(TeamResource::make($this->teams->findOrFail($user, $team->id), $user));
    }
}
