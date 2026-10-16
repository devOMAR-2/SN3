<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPagination;
use App\Http\Resources\TeamResource;
use App\Http\Responses\ApiResponse;
use App\Models\Team;
use App\Models\User;
use App\Support\Tenancy\AccessibleTeams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Read side of teams (API_SPEC.md §2 `GET /me/teams`, §3 `GET /teams`, `GET /teams/{team_id}`).
 * The `{team}` parameter is resolved through AccessibleTeams (TenancyServiceProvider), so a team
 * the caller does not belong to is a 404 before this controller runs.
 */
class TeamController extends Controller
{
    public function __construct(private readonly AccessibleTeams $teams) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = $this->teams->query($user)->orderByDesc('created_at')->orderByDesc('id');

        return CursorPagination::respond($request, $query, fn (Team $team): array => TeamResource::make($team, $user));
    }

    public function show(Request $request, Team $team): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('view', $team);

        return ApiResponse::data(TeamResource::make($team, $user));
    }
}
