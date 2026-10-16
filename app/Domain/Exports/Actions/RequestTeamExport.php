<?php

namespace App\Domain\Exports\Actions;

use App\Domain\Exports\TeamExportFormat;
use App\Domain\Exports\TeamExportStatus;
use App\Jobs\BuildTeamExport;
use App\Models\Team;
use App\Models\TeamExport;
use App\Models\User;
use App\Support\Exceptions\DomainConflictException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Starts an asynchronous team export (API_SPEC.md §3 `POST /teams/{team}/exports`, §18; ADR-024 §3).
 * Owner only. Returns the pending export; the BuildTeamExport job does the work.
 *
 * - Locks the team row first, like every team writer (ADR-018 §4), and re-checks on it: a team the
 *   caller no longer belongs to, or that went to the trash meanwhile, is 404; a lost ownership 409.
 * - An archived team can be exported: an export reads the team and changes nothing in it
 *   (ADR-024 §3), and DECIDED-011 asks for exports before deletion.
 * - At most one export per team is in flight: another request meanwhile is 409. The partial unique
 *   index `team_exports_one_in_flight_unique` backs this up, with the same 409.
 * - The job is dispatched after the commit, so it never runs against an uncommitted row.
 */
final class RequestTeamExport
{
    public function handle(Team $team, User $actor): TeamExport
    {
        return DB::transaction(function () use ($team, $actor): TeamExport {
            $locked = Team::query()->whereKey($team->id)->lock(Team::WRITE_LOCK)->firstOrFail();

            if ($locked->roleOf($actor) === null) {
                throw (new ModelNotFoundException)->setModel(Team::class, [$team->id]);
            }

            if (! Gate::forUser($actor)->allows('export', $locked)) {
                throw new DomainConflictException('Team ownership changed; reload and try again.');
            }

            $inFlight = TeamExport::query()
                ->where('team_id', $locked->id)
                ->whereIn('status', [TeamExportStatus::Pending->value, TeamExportStatus::Processing->value])
                ->exists();

            if ($inFlight) {
                throw new DomainConflictException('An export of this team is already in progress.');
            }

            $export = new TeamExport;
            $export->forceFill([
                'team_id' => $locked->id,
                'requested_by' => $actor->id,
                'status' => TeamExportStatus::Pending,
                'format_version' => TeamExportFormat::VERSION,
                'expires_at' => now()->addHours(TeamExport::TTL_HOURS),
            ]);

            try {
                $export->save();
            } catch (UniqueConstraintViolationException) {
                // team_exports_one_in_flight_unique: the same answer as the check above.
                throw new DomainConflictException('An export of this team is already in progress.');
            }

            BuildTeamExport::dispatch($export->id)->afterCommit();

            Log::info('team_export.requested', [
                'team_id' => $locked->id,
                'export_id' => $export->id,
                'actor_id' => $actor->id,
            ]);

            return $export;
        });
    }
}
