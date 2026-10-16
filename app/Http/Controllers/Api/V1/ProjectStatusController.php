<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Workflows\Actions\CreateStatus;
use App\Domain\Workflows\Actions\DeleteStatus;
use App\Domain\Workflows\Actions\ReorderStatuses;
use App\Domain\Workflows\Actions\UpdateStatus;
use App\Domain\Workflows\StatusCategory;
use App\Domain\Workflows\WorkflowVersion;
use App\Http\Concurrency\IfMatch;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPagination;
use App\Http\Requests\Workflows\DestroyStatusRequest;
use App\Http\Requests\Workflows\ReorderStatusesRequest;
use App\Http\Requests\Workflows\StoreStatusRequest;
use App\Http\Requests\Workflows\UpdateStatusRequest;
use App\Http\Resources\ProjectStatusResource;
use App\Http\Responses\ApiResponse;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Workflow statuses (API_SPEC.md §5, FR-CUST-001, ADR-021): list in board order, add, edit,
 * delete and reorder.
 *
 * `{project}` resolves through AccessibleProjects, so a hidden project's statuses are a 404 like the
 * project itself. `{status}` must belong to that project, else 404. Reading needs only access to the
 * project; changing the workflow needs `manageWorkflow` (the Owner or a project manager; never a
 * Team Viewer).
 *
 * Every response carries the project's workflow version as `meta.workflow_version` and as an
 * `ETag` (`"<n>"`); `PUT /statuses/order` requires it in `If-Match` (ADR-021 §3).
 */
class ProjectStatusController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $query = $project->statuses()->getQuery()->orderBy('position')->orderBy('id');

        // The version was read with the project (route binding), before the statuses: a change
        // committed in between can only make it older than the list, so the client's next reorder
        // gets a 412 and reloads, never a silent overwrite.
        $version = $project->workflow_version;
        $response = CursorPagination::respond($request, $query, fn (ProjectStatus $status): array => ProjectStatusResource::make($status), ['workflow_version' => $version]);

        return self::withEtag($response, $version);
    }

    public function store(StoreStatusRequest $request, Project $project, CreateStatus $createStatus): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{name: string, category: string, color?: string|null} $input */
        $input = $request->validated();

        $status = $createStatus->handle($project, $user, $input['name'], StatusCategory::from($input['category']), $input['color'] ?? null);

        return self::respond($status, 201);
    }

    public function update(UpdateStatusRequest $request, Project $project, string $status, UpdateStatus $updateStatus): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{name?: string, color?: string|null, category?: string} $changes */
        $changes = $request->validated();

        $updated = $updateStatus->handle($project, self::status($project, $status), $user, IfMatch::version($request), $changes);

        return self::respond($updated);
    }

    public function destroy(DestroyStatusRequest $request, Project $project, string $status, DeleteStatus $deleteStatus): Response
    {
        /** @var User $user */
        $user = $request->user();

        $version = $deleteStatus->handle($project, self::status($project, $status), $user, IfMatch::version($request), $request->replacementId());

        return response()->noContent()->withHeaders(['ETag' => WorkflowVersion::etag($version)]);
    }

    public function reorder(ReorderStatusesRequest $request, Project $project, ReorderStatuses $reorderStatuses): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $statuses = $reorderStatuses->handle($project, $user, IfMatch::version($request), $request->statusIds());
        $version = $statuses->first()?->project->workflow_version ?? $project->workflow_version;

        return self::withEtag(ApiResponse::data(
            $statuses->map(fn (ProjectStatus $status): array => ProjectStatusResource::make($status))->values()->all(),
            meta: ['workflow_version' => $version],
        ), $version);
    }

    /**
     * A single status, with the workflow version of the locked project the action loaded.
     */
    private static function respond(ProjectStatus $status, int $code = 200): JsonResponse
    {
        $version = $status->project->workflow_version;

        return self::withEtag(ApiResponse::data(ProjectStatusResource::make($status), $code, meta: ['workflow_version' => $version]), $version);
    }

    private static function withEtag(JsonResponse $response, int $version): JsonResponse
    {
        $response->headers->set('ETag', WorkflowVersion::etag($version));

        return $response;
    }

    /**
     * A status of this project, or 404 (a status of another project included).
     *
     * @throws ModelNotFoundException<ProjectStatus>
     */
    private static function status(Project $project, string $id): ProjectStatus
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException)->setModel(ProjectStatus::class);
        }

        return $project->statuses()->whereKey(strtolower($id))->firstOrFail();
    }
}
