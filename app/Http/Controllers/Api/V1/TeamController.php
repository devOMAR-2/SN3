<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Teams\Actions\CreateTeam;
use App\Domain\Teams\Actions\UpdateTeam;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPagination;
use App\Http\Requests\Teams\StoreTeamRequest;
use App\Http\Requests\Teams\UpdateTeamRequest;
use App\Http\Resources\TeamResource;
use App\Http\Responses\ApiResponse;
use App\Models\Team;
use App\Models\User;
use App\Support\Tenancy\AccessibleTeams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Teams (API_SPEC.md §2 `GET /me/teams`, §3 `GET/POST /teams`, `GET/PATCH /teams/{team_id}`).
 * The `{team}` parameter is resolved through AccessibleTeams (TenancyServiceProvider), so a team
 * the caller does not belong to is a 404 before this controller runs; a member without the right
 * role gets 403.
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

    public function store(StoreTeamRequest $request, CreateTeam $createTeam): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{name: string, slug?: string|null, description?: string|null} $input */
        $input = $request->validated();

        $team = $createTeam->handle($user, $input['name'], $input['slug'] ?? null, $input['description'] ?? null);

        return ApiResponse::data(TeamResource::make($this->teams->findOrFail($user, $team->id), $user), 201);
    }

    public function update(UpdateTeamRequest $request, Team $team, UpdateTeam $updateTeam): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{name?: string, slug?: string, description?: string|null} $changes */
        $changes = $request->validated();

        return ApiResponse::data(TeamResource::make($updateTeam->handle($team, $changes), $user));
    }
}
