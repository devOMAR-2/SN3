<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Projects\Actions\UpdateProjectSettings;
use App\Http\Concurrency\IfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\UpdateProjectSettingsRequest;
use App\Http\Resources\ProjectSettingsResource;
use App\Http\Responses\ApiResponse;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Project configuration (API_SPEC.md §5 `GET/PATCH /projects/{project}/settings`, ADR-021 §6).
 * Anyone who can see the project reads it; changing it needs the same right as
 * `PATCH /projects/{project}` and its `If-Match` version.
 */
class ProjectSettingsController extends Controller
{
    public function show(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return ApiResponse::data(ProjectSettingsResource::make($project));
    }

    public function update(UpdateProjectSettingsRequest $request, Project $project, UpdateProjectSettings $updateSettings): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{sprints_enabled?: bool} $changes */
        $changes = $request->validated();

        $updated = $updateSettings->handle($project, $user, IfMatch::version($request), $changes);

        return ApiResponse::data(ProjectSettingsResource::make($updated));
    }
}
