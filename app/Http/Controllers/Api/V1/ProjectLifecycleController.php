<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Projects\Actions\ArchiveProject;
use App\Domain\Projects\Actions\RestoreDeletedProject;
use App\Domain\Projects\Actions\TrashProject;
use App\Http\Concurrency\IfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lifecycle\ArchiveProjectRequest;
use App\Http\Requests\Lifecycle\RestoreDeletedProjectRequest;
use App\Http\Requests\Lifecycle\TrashProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Http\Responses\ApiResponse;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\AccessibleProjects;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Project archive and trash (API_SPEC.md §4, FR-PROJ-008, DECIDED-011, ADR-022), all Owner or
 * Project Manager: `POST /projects/{project}/archive`, `POST /projects/{project}/restore`
 * (unarchive) and `DELETE /projects/{project}` (30-day trash), which take `If-Match`, and
 * `POST /projects/{project}/restore-deleted`.
 *
 * `{project}` resolves through AccessibleProjects (404 when hidden; 403 when visible but not
 * allowed). `{trashedProject}` resolves through RecoverableResources: only someone who could see the
 * project and may recover it gets past it, within 30 days; everyone else gets 404.
 */
class ProjectLifecycleController extends Controller
{
    public function __construct(private readonly AccessibleProjects $projects) {}

    public function archive(ArchiveProjectRequest $request, Project $project, ArchiveProject $archive): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return $this->respond($actor, $archive->handle($project, $actor, IfMatch::version($request), true));
    }

    public function unarchive(ArchiveProjectRequest $request, Project $project, ArchiveProject $archive): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return $this->respond($actor, $archive->handle($project, $actor, IfMatch::version($request), false));
    }

    public function destroy(TrashProjectRequest $request, Project $project, TrashProject $trash): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $trash->handle($project, $actor, IfMatch::version($request));

        return response()->noContent();
    }

    public function restoreDeleted(RestoreDeletedProjectRequest $request, Project $trashedProject, RestoreDeletedProject $restore): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return $this->respond($actor, $restore->handle($trashedProject, $actor));
    }

    private function respond(User $actor, Project $project): JsonResponse
    {
        // Re-read through the normal resolver, with the caller's own memberships loaded.
        return ApiResponse::data(ProjectResource::make($this->projects->findOrFail($actor, $project->id), $actor));
    }
}
