<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tasks\Actions\CreateLabel;
use App\Domain\Tasks\Actions\DeleteLabel;
use App\Domain\Tasks\Actions\UpdateLabel;
use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPagination;
use App\Http\Requests\Labels\DestroyLabelRequest;
use App\Http\Requests\Labels\StoreLabelRequest;
use App\Http\Requests\Labels\UpdateLabelRequest;
use App\Http\Resources\LabelResource;
use App\Http\Responses\ApiResponse;
use App\Models\Label;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Project labels (API_SPEC.md §5, FR-TASK-001, ADR-023 §6). Reading needs access to the project;
 * writing is "Owner/Project Manager" (`manageLabels`). A `{label}` of another project is a 404.
 * The list is in name order (`name`, then `id`).
 */
class LabelController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $query = $project->labels()->getQuery()->orderBy('name')->orderBy('id');

        return CursorPagination::respond($request, $query, fn (Label $label): array => LabelResource::make($label));
    }

    public function store(StoreLabelRequest $request, Project $project, CreateLabel $createLabel): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{name: string, color?: string|null} $input */
        $input = $request->validated();

        return ApiResponse::data(LabelResource::make($createLabel->handle($project, $user, $input['name'], $input['color'] ?? null)), 201);
    }

    public function update(UpdateLabelRequest $request, Project $project, string $label, UpdateLabel $updateLabel): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{name?: string, color?: string|null} $changes */
        $changes = $request->validated();

        return ApiResponse::data(LabelResource::make($updateLabel->handle($project, self::label($project, $label), $user, $changes)));
    }

    public function destroy(DestroyLabelRequest $request, Project $project, string $label, DeleteLabel $deleteLabel): Response
    {
        /** @var User $user */
        $user = $request->user();

        $deleteLabel->handle($project, self::label($project, $label), $user);

        return response()->noContent();
    }

    /**
     * @throws ModelNotFoundException<Label>
     */
    private static function label(Project $project, string $id): Label
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException)->setModel(Label::class);
        }

        return $project->labels()->whereKey(strtolower($id))->firstOrFail();
    }
}
