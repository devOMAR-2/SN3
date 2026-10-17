<?php

namespace App\Jobs;

use App\Domain\Exports\ExportTooLargeException;
use App\Domain\Exports\TeamExportContext;
use App\Domain\Exports\TeamExportStatus;
use App\Domain\Exports\TeamExportStorage;
use App\Domain\Exports\TeamExportWriter;
use App\Models\Team;
use App\Models\TeamExport;
use App\Models\User;
use App\Support\Tenancy\AccessibleProjects;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Builds one team export archive (ADR-024 §3). The payload is the export ID only (AGENTS.md §5
 * "Async"); everything else is re-resolved when the job runs, and again before the result is kept:
 *
 * - the export must still exist, be pending or processing, and not have expired;
 * - the team must exist and not be in the trash;
 * - the requester must still be an enabled user and the team's Owner (TeamPolicy::export).
 *
 * Otherwise the export is refused: marked `failed`, logged as `team_export.refused` with a reason
 * code, and not retried. Projects are resolved through AccessibleProjects for the requester at run
 * time (TeamExportContext), so the archive holds exactly what the Owner can see then.
 *
 * Unexpected errors are retried by the worker (`--tries=3`); after the last attempt failed() marks
 * the export `failed`. Partial files never survive: the archive is built in the export's own work
 * directory (TeamExportStorage::workDir) and copied to the export disk only when complete. That
 * directory is deleted when the job ends, cleared when a retry starts, and swept by exports:prune
 * if a killed worker left it behind.
 */
final class BuildTeamExport implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $exportId) {}

    public function handle(AccessibleProjects $projects, TeamExportWriter $writer, TeamExportStorage $storage): void
    {
        $export = TeamExport::query()->find($this->exportId);

        if ($export === null || ! $export->status->inFlight()) {
            return;
        }

        if ($export->isExpired()) {
            $this->refuse($export, 'expired');

            return;
        }

        [$team, $actor, $reason] = $this->resolve($export);

        if ($team === null || $actor === null) {
            $this->refuse($export, $reason);

            return;
        }

        $export->forceFill(['status' => TeamExportStatus::Processing])->save();

        // Deterministic per export: whatever a killed earlier attempt left there goes first, and
        // exports:prune removes directories that outlive WORK_TTL_MINUTES.
        $workDir = $storage->workDir($export);
        File::deleteDirectory($workDir);
        $key = $storage->key($export);
        $stored = false;
        $size = 0;
        $refusal = 'not_built';

        try {
            $zip = $writer->build(new TeamExportContext($team, $actor, $export, $projects), $workDir);
            $bytes = filesize($zip);
            $sha256 = hash_file('sha256', $zip);
            $stream = fopen($zip, 'rb');

            if ($bytes === false || $sha256 === false || $stream === false) {
                throw new RuntimeException('Cannot read the export archive.');
            }

            $size = $bytes;

            try {
                $storage->disk()->writeStream($key, $stream);
                $stored = true;
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $refusal = $this->complete($export, $key, $size, $sha256);
        } catch (ExportTooLargeException) {
            // Beyond what a zip archive without ZIP64 holds (CORE-008, ADR-027 §7): retrying cannot help.
            $refusal = 'too_large';
        } catch (Throwable $e) {
            if ($stored) {
                $storage->disk()->delete($key);
            }

            throw $e;
        } finally {
            File::deleteDirectory($workDir);
        }

        if ($refusal !== null) {
            $storage->disk()->delete($key);
            $this->refuse($export, $refusal);

            return;
        }

        Log::info('team_export.completed', [
            'team_id' => $export->team_id,
            'export_id' => $export->id,
            'actor_id' => $export->requested_by,
            'size_bytes' => $size,
        ]);
    }

    /**
     * After the last failed attempt (or a timeout): the export fails and its file, if any, goes.
     */
    public function failed(?Throwable $exception): void
    {
        $export = TeamExport::query()->find($this->exportId);

        if ($export === null) {
            return;
        }

        $storage = app(TeamExportStorage::class);
        $storage->disk()->delete($storage->key($export));
        File::deleteDirectory($storage->workDir($export));

        if ($export->status->inFlight()) {
            $this->markFailed($export);
        }

        // IDs only: exception messages may quote row values.
        Log::warning('team_export.failed', [
            'team_id' => $export->team_id,
            'export_id' => $export->id,
            'actor_id' => $export->requested_by,
        ]);
    }

    /**
     * The team (not trashed) and the requester, if the requester may still export it.
     *
     * @return array{0: Team|null, 1: User|null, 2: string}
     */
    private function resolve(TeamExport $export): array
    {
        $team = Team::query()->find($export->team_id);

        if ($team === null) {
            return [null, null, 'team_unavailable'];
        }

        $actor = User::query()->find($export->requested_by);

        if ($actor === null || $actor->isDisabled()) {
            return [null, null, 'requester_unavailable'];
        }

        if (! Gate::forUser($actor)->allows('export', $team)) {
            return [null, null, 'not_owner'];
        }

        return [$team, $actor, ''];
    }

    /**
     * Marks the export ready, after re-checking the team and the requester on the locked export row
     * (a trash or ownership transfer during the build discards the result). Returns null when kept,
     * or the reason it was not.
     */
    private function complete(TeamExport $export, string $key, int $size, string $sha256): ?string
    {
        return DB::transaction(function () use ($export, $key, $size, $sha256): ?string {
            $locked = TeamExport::query()->whereKey($export->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== TeamExportStatus::Processing) {
                return 'superseded';
            }

            [$team, $actor, $reason] = $this->resolve($locked);

            if ($team === null || $actor === null) {
                return $reason;
            }

            $locked->forceFill([
                'status' => TeamExportStatus::Ready,
                'storage_key' => $key,
                'size_bytes' => $size,
                'sha256' => $sha256,
                'completed_at' => now(),
                'expires_at' => now()->addHours(TeamExport::TTL_HOURS),
            ])->save();

            return null;
        });
    }

    private function refuse(TeamExport $export, string $reason): void
    {
        $this->markFailed($export);

        Log::warning('team_export.refused', [
            'team_id' => $export->team_id,
            'export_id' => $export->id,
            'actor_id' => $export->requested_by,
            'reason' => $reason,
        ]);
    }

    private function markFailed(TeamExport $export): void
    {
        TeamExport::query()
            ->whereKey($export->id)
            ->whereIn('status', [TeamExportStatus::Pending->value, TeamExportStatus::Processing->value])
            ->update([
                'status' => TeamExportStatus::Failed->value,
                'completed_at' => now(),
                'expires_at' => now()->addHours(TeamExport::TTL_HOURS),
                'updated_at' => now(),
            ]);
    }
}
