<?php

use App\Domain\Exports\PruneTeamExports;
use App\Domain\Exports\TeamExportFormat;
use App\Domain\Exports\TeamExportStatus;
use App\Domain\Trash\PurgeTrash;
use App\Models\Team;
use App\Models\TeamExport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// CORE-012: exports and their archives are deleted 24 hours after they complete (API_SPEC.md §13,
// §18; ADR-024 §4), and the `team_exports` schema (DATABASE.md §20).

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('exports');
    $this->workRoot = sys_get_temp_dir().'/sn3-export-work-test-'.Str::random(8);
    config(['sn3.exports.work_dir' => $this->workRoot]);
    $this->team = Team::factory()->create();
});

afterEach(function () {
    File::deleteDirectory($this->workRoot);
});

function storedExport(Team $team, TeamExportStatus $status, DateTimeInterface $expiresAt, bool $withFile = true): TeamExport
{
    $export = new TeamExport;
    $export->id = (string) Str::uuid7();
    $key = "archives/{$team->id}/{$export->id}.zip";

    $export->forceFill([
        'team_id' => $team->id,
        'requested_by' => $team->owner_user_id,
        'status' => $status,
        'format_version' => TeamExportFormat::VERSION,
        'expires_at' => $expiresAt,
    ] + ($status === TeamExportStatus::Ready ? ['storage_key' => $key, 'size_bytes' => 7, 'sha256' => str_repeat('a', 64), 'completed_at' => now()] : []))->save();

    if ($withFile) {
        Storage::disk('exports')->put($key, 'archive');
    }

    return $export;
}

it('deletes expired exports with their archives and keeps the rest', function () {
    Log::spy();
    $expired = storedExport($this->team, TeamExportStatus::Ready, now()->subMinute());
    $failed = storedExport($this->team, TeamExportStatus::Failed, now()->subMinute(), withFile: false);
    $stalePending = storedExport(Team::factory()->create(), TeamExportStatus::Pending, now()->subSecond(), withFile: false);
    $fresh = storedExport(Team::factory()->create(), TeamExportStatus::Ready, now()->addHour());

    $this->artisan('exports:prune')
        ->expectsOutputToContain('Pruned 3 expired export(s), 0 orphaned archive(s) and 0 stale work director(ies).')
        ->assertSuccessful();

    expect(TeamExport::query()->pluck('id')->all())->toBe([$fresh->id])
        ->and(Storage::disk('exports')->allFiles())->toBe(["archives/{$fresh->team_id}/{$fresh->id}.zip"]);

    foreach ([$expired, $failed, $stalePending] as $export) {
        Log::shouldHaveReceived('info')->with('team_export.pruned', ['team_id' => $export->team_id, 'export_id' => $export->id])->once();
    }
});

it('deletes archives whose export row is gone, e.g. after the trash purge, and nothing else', function () {
    $kept = storedExport($this->team, TeamExportStatus::Ready, now()->addHour());
    $orphanId = (string) Str::uuid7();
    Storage::disk('exports')->put("archives/{$this->team->id}/{$orphanId}.zip", 'orphan');
    // Outside the `archives/` prefix nothing is touched, not even a file that looks like an archive.
    Storage::disk('exports')->put('README.txt', 'not an export');
    Storage::disk('exports')->put("{$this->team->id}/{$orphanId}.zip", 'someone else\'s file');

    expect(app(PruneTeamExports::class)->handle())->toBe(['exports' => 0, 'orphans' => 1, 'work_dirs' => 0])
        ->and(Storage::disk('exports')->allFiles())->toEqualCanonicalizing([
            'README.txt',
            "{$this->team->id}/{$orphanId}.zip",
            "archives/{$kept->team_id}/{$kept->id}.zip",
        ]);
});

it('works through more rows than one batch and is idempotent', function () {
    $teams = Team::factory()->count(PruneTeamExports::BATCH + 5)->create();
    foreach ($teams as $team) {
        storedExport($team, TeamExportStatus::Failed, now()->subHour(), withFile: false);
    }

    expect(app(PruneTeamExports::class)->handle()['exports'])->toBe(PruneTeamExports::BATCH + 5)
        ->and(app(PruneTeamExports::class)->handle())->toBe(['exports' => 0, 'orphans' => 0, 'work_dirs' => 0])
        ->and(TeamExport::query()->count())->toBe(0);
});

it('goes with its team when the trash is purged, and the sweep then removes the archive', function () {
    $export = storedExport($this->team, TeamExportStatus::Ready, now()->addDays(60));
    $this->team->forceFill(['deleted_at' => now()->subDays(31)])->save();

    app(PurgeTrash::class)->handle();

    expect(TeamExport::query()->whereKey($export->id)->exists())->toBeFalse()
        ->and(app(PruneTeamExports::class)->handle()['orphans'])->toBe(1)
        ->and(Storage::disk('exports')->allFiles())->toBe([]);
});

it('removes work directories a killed worker left behind once they are two hours old, and keeps fresh ones', function () {
    Log::spy();
    $stale = $this->workRoot.'/'.Str::uuid7();
    $fresh = $this->workRoot.'/'.Str::uuid7();
    foreach ([$stale, $fresh] as $directory) {
        mkdir($directory.'/data', 0700, true);
        file_put_contents($directory.'/data/members.json', '[{"email":"plain@example.com"}]');
    }
    touch($stale, now()->subMinutes(121)->getTimestamp());

    expect(app(PruneTeamExports::class)->handle())->toBe(['exports' => 0, 'orphans' => 0, 'work_dirs' => 1])
        ->and(is_dir($stale))->toBeFalse()
        ->and(is_dir($fresh))->toBeTrue();
    Log::shouldHaveReceived('info')->with('team_export.work_dir_pruned', ['export_id' => basename($stale)])->once();

    // Two hours later the fresh one goes too.
    expect(app(PruneTeamExports::class)->handle(now()->addMinutes(121))['work_dirs'])->toBe(1)
        ->and(is_dir($fresh))->toBeFalse();
});

// --- Schema ---------------------------------------------------------------------------------------

it('allows only one export in flight per team in the database', function () {
    storedExport($this->team, TeamExportStatus::Pending, now()->addDay(), withFile: false);

    expect(fn () => DB::transaction(fn () => storedExport($this->team, TeamExportStatus::Processing, now()->addDay(), withFile: false)))
        ->toThrow(QueryException::class, 'team_exports_one_in_flight_unique');

    // Finished exports do not count.
    storedExport($this->team, TeamExportStatus::Ready, now()->addDay(), withFile: false);
    storedExport($this->team, TeamExportStatus::Failed, now()->addDay(), withFile: false);

    expect(TeamExport::query()->count())->toBe(3);
});

it('requires a ready export to have its file, size, checksum and completion time', function () {
    $export = storedExport($this->team, TeamExportStatus::Pending, now()->addDay(), withFile: false);

    expect(fn () => DB::transaction(fn () => TeamExport::query()->whereKey($export->id)->update(['status' => 'ready'])))
        ->toThrow(QueryException::class, 'team_exports_ready_check');
    expect(fn () => DB::transaction(fn () => TeamExport::query()->whereKey($export->id)->update(['status' => 'done'])))
        ->toThrow(QueryException::class, 'team_exports_status_check');
});

it('schedules the hourly export prune', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'exports:prune'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('50 * * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});
