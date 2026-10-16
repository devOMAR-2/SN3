<?php

use App\Models\Team;
use App\Models\TeamExport;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

// CORE-012 (ADR-024 §3): two simultaneous export requests for one team start exactly one export.
// The requests run in separate PHP processes on committed data, so this test does not use
// RefreshDatabase and cleans up after itself. Each child waits on an advisory lock the test holds
// until both are queued on it (the barrier pattern of OwnershipTransferConcurrencyTest).

const EXPORT_BARRIER_KEY = 640_024;

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);

    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
});

afterEach(function () {
    $exports = TeamExport::query()->where('team_id', $this->team->id)->pluck('id')->all();

    foreach ($exports as $id) {
        DB::table('jobs')->where('payload', 'like', '%'.$id.'%')->delete();
    }

    Team::withTrashed()->whereKey($this->team->id)->forceDelete();
    User::query()->whereKey($this->owner->id)->delete();
});

function requestExportInProcess(Team $team, User $owner): string
{
    $code = sprintf(
        'DB::select("select pg_advisory_lock_shared(%d)"); try { app(App\Domain\Exports\Actions\RequestTeamExport::class)->handle(App\Models\Team::findOrFail("%s"), App\Models\User::findOrFail("%s")); echo "requested"; } catch (Throwable $e) { echo class_basename($e); }',
        EXPORT_BARRIER_KEY,
        $team->id,
        $owner->id,
    );

    return 'php artisan tinker --execute='.escapeshellarg($code).' --no-interaction';
}

it('starts one export when two requests race; the other is refused with 409', function () {
    $connection = config('database.connections.pgsql');
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => (string) $connection['host'],
        'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => (string) $connection['database'],
        'DB_USERNAME' => (string) $connection['username'],
        'DB_PASSWORD' => (string) $connection['password'],
        'DB_URL' => '',
        // Queue the job instead of running it inside the racing process.
        'QUEUE_CONNECTION' => 'database',
    ];

    DB::select('select pg_advisory_lock(?)', [EXPORT_BARRIER_KEY]);

    try {
        $pool = Process::pool(fn ($pool) => [
            $pool->path(base_path())->env($env)->timeout(60)->command(requestExportInProcess($this->team, $this->owner)),
            $pool->path(base_path())->env($env)->timeout(60)->command(requestExportInProcess($this->team, $this->owner)),
        ])->start();

        $deadline = microtime(true) + 30;
        while (DB::table('pg_locks')->where('locktype', 'advisory')->where('objid', EXPORT_BARRIER_KEY)->where('objsubid', 1)->whereRaw('database = (select oid from pg_database where datname = current_database())')->where('granted', false)->count() < 2) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Both export processes did not reach the barrier within 30 seconds.');
            }
            usleep(20_000);
        }
    } finally {
        DB::select('select pg_advisory_unlock(?)', [EXPORT_BARRIER_KEY]);
    }

    $outcomes = $pool->wait()->collect()->map(fn ($result) => trim($result->output()))->sort()->values()->all();

    expect($outcomes)->toBe(['DomainConflictException', 'requested'])
        ->and(TeamExport::query()->where('team_id', $this->team->id)->count())->toBe(1);

    $id = TeamExport::query()->where('team_id', $this->team->id)->sole()->id;
    expect(DB::table('jobs')->where('payload', 'like', '%'.$id.'%')->count())->toBe(1);
});
