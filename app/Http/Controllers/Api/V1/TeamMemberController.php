<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Teams\Actions\ChangeMemberRole;
use App\Domain\Teams\Actions\RemoveMember;
use App\Domain\Teams\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPagination;
use App\Http\Requests\Teams\UpdateMemberRoleRequest;
use App\Http\Resources\TeamMemberResource;
use App\Http\Responses\ApiResponse;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Team memberships (API_SPEC.md §3): list, change role, remove or leave.
 * `{member}` is a user ID, resolved only among this team's memberships (else 404).
 */
class TeamMemberController extends Controller
{
    public function index(Request $request, Team $team): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('view', $team);

        $withEmail = $team->roleOf($user)?->managesTeam() ?? false;
        $query = $team->memberships()->getQuery()->with('user')->orderByDesc('created_at')->orderByDesc('id');

        return CursorPagination::respond(
            $request,
            $query,
            fn (TeamMembership $membership): array => TeamMemberResource::make($membership, $withEmail),
        );
    }

    public function update(UpdateMemberRoleRequest $request, Team $team, string $member, ChangeMemberRole $changeRole): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $membership = $this->membership($team, $member);

        Gate::authorize('changeMemberRole', [$team, $membership->user]);

        $updated = $changeRole->handle($team, $user, $membership, TeamRole::from($request->string('role')->toString()));

        return ApiResponse::data(TeamMemberResource::make($updated, $team->roleOf($user)?->managesTeam() ?? false));
    }

    public function destroy(Request $request, Team $team, string $member, RemoveMember $removeMember): Response
    {
        /** @var User $user */
        $user = $request->user();
        $membership = $this->membership($team, $member);

        Gate::authorize('removeMember', [$team, $membership->user]);

        $removeMember->handle($team, $user, $membership);

        return response()->noContent();
    }

    /**
     * @throws ModelNotFoundException<TeamMembership>
     */
    private function membership(Team $team, string $userId): TeamMembership
    {
        if (! Str::isUuid($userId)) {
            throw (new ModelNotFoundException)->setModel(TeamMembership::class);
        }

        return $team->memberships()->where('user_id', $userId)->with('user')->firstOrFail();
    }
}
