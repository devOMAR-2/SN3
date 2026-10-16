<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Exports\Actions\RequestTeamExport;
use App\Domain\Exports\TeamExportStatus;
use App\Domain\Exports\TeamExportStorage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exports\StoreTeamExportRequest;
use App\Http\Resources\TeamExportResource;
use App\Http\Responses\ApiResponse;
use App\Models\Team;
use App\Models\TeamExport;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Team exports (API_SPEC.md §3, §13, §18; DECIDED-004, DECIDED-011; ADR-024), all Team Owner only:
 *
 * - `POST /teams/{team}/exports` starts an asynchronous export: 202 with the export (and a
 *   `Location` header). `Idempotency-Key` is required.
 * - `GET /teams/{team}/exports/{export}` reads its state and, when ready, a signed download URL.
 *   It never starts a job (EXP-01).
 * - `GET /teams/{team}/exports/{export}/download` (signed) streams the archive.
 *
 * `{team}` resolves through AccessibleTeams (404 for non-members and trashed teams). Members without
 * the right get 403 before the export ID is even looked up, so they learn nothing about exports.
 * An export is visible only to the Owner who requested it; any other ID is 404.
 */
class TeamExportController extends Controller
{
    public function store(StoreTeamExportRequest $request, Team $team, RequestTeamExport $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $export = $action->handle($team, $user)->refresh();

        return ApiResponse::data(TeamExportResource::make($export), 202, [
            'Location' => route('api.teams.exports.show', ['team' => $team->id, 'export' => $export->id], false),
        ]);
    }

    public function show(Request $request, Team $team, string $export): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        Gate::forUser($user)->authorize('export', $team);

        return ApiResponse::data(TeamExportResource::make($this->find($team, $user, $export)));
    }

    public function download(Request $request, Team $team, string $export, TeamExportStorage $storage): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        Gate::forUser($user)->authorize('export', $team);

        $found = $this->find($team, $user, $export);

        // Only a ready export has a download; one past its 24 hours (or already pruned) is 410.
        if ($found->status !== TeamExportStatus::Ready) {
            throw (new ModelNotFoundException)->setModel(TeamExport::class, [$found->id]);
        }

        if (! $found->isDownloadable() || ! $storage->disk()->exists($storage->key($found))) {
            throw new HttpException(410);
        }

        Log::info('team_export.downloaded', [
            'team_id' => $team->id,
            'export_id' => $found->id,
            'actor_id' => $user->id,
        ]);

        return $storage->disk()->download($storage->key($found), 'sn3-team-export-'.$found->id.'.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * @throws ModelNotFoundException<TeamExport>
     */
    private function find(Team $team, User $user, string $id): TeamExport
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException)->setModel(TeamExport::class, [$id]);
        }

        return TeamExport::query()
            ->where('team_id', $team->id)
            ->where('requested_by', $user->id)
            ->whereKey($id)
            ->firstOrFail();
    }
}
