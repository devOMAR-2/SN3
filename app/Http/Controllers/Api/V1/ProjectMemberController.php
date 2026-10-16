<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Projects\Actions\RemoveProjectMember;
use App\Domain\Projects\Actions\SetProjectMember;
use App\Domain\Projects\ProjectRole;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPagination;
use App\Http\Requests\Projects\PutProjectMemberRequest;
use App\Http\Resources\ProjectMemberResource;
use App\Http\Responses\ApiResponse;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Explicit project memberships (API_SPEC.md §4, FR-PROJ-006): list, add or change a role (PUT),
 * remove. `{project}` is resolved through AccessibleProjects (404 when hidden); `{member}` is a
 * user ID, which must belong to the project's team (PUT) or hold a membership (DELETE), else 404.
 */
class ProjectMemberController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('view', $project);

        $withEmail = $project->team->roleOf($user)?->managesTeam() ?? false;
        $query = $project->memberships()->getQuery()->with('user')->orderByDesc('created_at')->orderByDesc('id');

        return CursorPagination::respond(
            $request,
            $query,
            fn (ProjectMembership $membership): array => ProjectMemberResource::make($membership, $withEmail),
        );
    }

    public function update(PutProjectMemberRequest $request, Project $project, string $member, SetProjectMember $setMember): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $membership = $setMember->handle(
            $project,
            $user,
            self::userId($member),
            ProjectRole::from($request->string('role')->toString()),
        );

        return ApiResponse::data(
            ProjectMemberResource::make($membership, $project->team->roleOf($user)?->managesTeam() ?? false),
            $membership->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function destroy(Request $request, Project $project, string $member, RemoveProjectMember $removeMember): Response
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('manageMembers', $project);

        $removeMember->handle($project, $user, self::userId($member));

        return response()->noContent();
    }

    /**
     * @throws ModelNotFoundException<ProjectMembership>
     */
    private static function userId(string $value): string
    {
        if (! Str::isUuid($value)) {
            throw (new ModelNotFoundException)->setModel(ProjectMembership::class);
        }

        return strtolower($value);
    }
}
