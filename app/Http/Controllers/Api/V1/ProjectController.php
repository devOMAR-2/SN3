<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Actions\UpdateProject;
use App\Domain\Projects\ProjectVisibility;
use App\Http\Concurrency\IfMatch;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPagination;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Http\Responses\ApiResponse;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\Tenancy\AccessibleProjects;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Projects (API_SPEC.md §4): `GET/POST /teams/{team}/projects`, `GET/PATCH /projects/{project}`.
 *
 * `{team}` resolves through AccessibleTeams and `{project}` through AccessibleProjects
 * (TenancyServiceProvider), so a foreign team and a project the caller may not see are both a 404
 * before this controller runs. The list is filtered by the same rule before pagination, so private
 * projects are never counted, paged over or hinted at.
 */
class ProjectController extends Controller
{
    public function __construct(private readonly AccessibleProjects $projects) {}

    public function index(Request $request, Team $team): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('view', $team);

        $query = $this->projects->inTeam($team, $user)->orderByDesc('created_at')->orderByDesc('id');

        return CursorPagination::respond($request, $query, fn (Project $project): array => ProjectResource::make($project, $user));
    }

    public function store(StoreProjectRequest $request, Team $team, CreateProject $createProject): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{name: string, key: string, description?: string|null, visibility?: string} $input */
        $input = $request->validated();

        $project = $createProject->handle(
            $team,
            $user,
            $input['name'],
            $input['key'],
            $input['description'] ?? null,
            ProjectVisibility::from($input['visibility'] ?? ProjectVisibility::Team->value),
        );

        return ApiResponse::data(ProjectResource::make($this->projects->findOrFail($user, $project->id), $user), 201);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        Gate::authorize('view', $project);

        return ApiResponse::data(ProjectResource::make($project, $user));
    }

    public function update(UpdateProjectRequest $request, Project $project, UpdateProject $updateProject): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{name?: string, key?: string, description?: string|null, visibility?: string} $changes */
        $changes = $request->validated();

        $updated = $updateProject->handle($project, $user, IfMatch::version($request), $changes);

        return ApiResponse::data(ProjectResource::make($updated, $user));
    }
}
