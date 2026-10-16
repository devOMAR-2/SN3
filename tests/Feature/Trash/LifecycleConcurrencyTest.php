<?php

use App\Domain\Teams\TeamRole;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

// CORE-010 under real concurrency (ADR-022 §6). Two PHP processes race on committed data, released
// together from an advisory-lock barrier, like OwnershipTransferConcurrencyTest (DB-T06). So this
// file does not use RefreshDatabase; it cleans up after itself.
//
// - Archiving a team while a project in it is edited: both lock the team row first, so either the
//   edit commits and then the team is archived, or the edit finds the team archived (423). Never an
//   edit that lands in an archived team.
// - Recovering a project while the purge (run as if two days later) deletes it: both lock the team
//   row first, and the purge repeats the trash condition in its DELETE, so either the project is
//   recovered and survives, or it is purged and the recovery is a 404. Never both, never a 500.

const LIFECYCLE_RACE_BARRIER_KEY = 640_010;

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);

    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->project = Project::factory()->for($this->team)->create(['name' => 'Before']);
    $this->member = TeamMembership::factory()->for($this->team)->role(TeamRole::Member)->create();
});

afterEach(function () {
    $userIds = TeamMembership::query()->where('team_id', $this->team->id)->pluck('user_id')->push($this->owner->id, $this->member->user_id)->unique()->all();

    ProjectMembership::query()->where('team_id', $this->team->id)->delete();
    Project::withTrashed()->where('team_id', $this->team->id)->forceDelete();
    Team::withTrashed()->whereKey($this->team->id)->forceDelete();
    User::query()->whereKey($userIds)->delete();
});

/**
 * Runs each PHP snippet in its own `php artisan tinker` process, all released together once every
 * process waits on the barrier, and returns their trimmed outputs, sorted.
 *
 * @param  list<string>  $snippets
 * @return list<string>
 */
function raceLifecycleInProcesses(array $snippets): array
{
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
    ];

    $commands = array_map(fn (string $snippet): string => 'php artisan tinker --no-interaction --execute='.escapeshellarg(sprintf(
        'DB::select("select pg_advisory_lock_shared(%d)"); try { %s } catch (Throwable $e) { echo class_basename($e); }',
        LIFECYCLE_RACE_BARRIER_KEY,
        $snippet,
    )), $snippets);

    DB::select('select pg_advisory_lock(?)', [LIFECYCLE_RACE_BARRIER_KEY]);

    try {
        $pool = Process::pool(function ($pool) use ($commands, $env): void {
            foreach ($commands as $command) {
                $pool->path(base_path())->env($env)->timeout(60)->command($command);
            }
        })->start();

        $deadline = microtime(true) + 30;
        while (DB::table('pg_locks')->where('locktype', 'advisory')->where('objid', LIFECYCLE_RACE_BARRIER_KEY)->where('objsubid', 1)->whereRaw('database = (select oid from pg_database where datname = current_database())')->where('granted', false)->count() < count($commands)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The racing processes did not reach the barrier within 30 seconds.');
            }
            usleep(20_000);
        }
    } finally {
        DB::select('select pg_advisory_unlock(?)', [LIFECYCLE_RACE_BARRIER_KEY]);
    }

    return $pool->wait()->collect()->map(fn ($result) => trim($result->output()))->sort()->values()->all();
}

it('never lets a project edit land in a team that is being archived', function () {
    $outcomes = raceLifecycleInProcesses([
        sprintf(
            'app(App\Domain\Teams\Actions\ArchiveTeam::class)->handle(App\Models\Team::findOrFail("%s"), App\Models\User::findOrFail("%s"), true); echo "archived";',
            $this->team->id,
            $this->owner->id,
        ),
        sprintf(
            'app(App\Domain\Projects\Actions\UpdateProject::class)->handle(App\Models\Project::findOrFail("%s"), App\Models\User::findOrFail("%s"), 1, ["name" => "After"]); echo "updated";',
            $this->project->id,
            $this->owner->id,
        ),
    ]);

    // The edit first (then the archive), or the archive first (then the edit is refused).
    expect([['archived', 'updated'], ['ResourceLockedException', 'archived']])->toContain($outcomes);

    $project = Project::query()->findOrFail($this->project->id);
    expect(Team::query()->findOrFail($this->team->id)->isArchived())->toBeTrue()
        ->and($project->name)->toBe($outcomes === ['archived', 'updated'] ? 'After' : 'Before')
        ->and($project->version)->toBe($outcomes === ['archived', 'updated'] ? 2 : 1);
});

it('either recovers or purges a project when a recovery races the purge, never both', function () {
    $this->project->forceFill(['deleted_at' => now()->subDays(29)])->save();

    $outcomes = raceLifecycleInProcesses([
        sprintf(
            'app(App\Domain\Projects\Actions\RestoreDeletedProject::class)->handle(App\Models\Project::withTrashed()->findOrFail("%s"), App\Models\User::findOrFail("%s")); echo "restored";',
            $this->project->id,
            $this->owner->id,
        ),
        // Two days later for the purge, so the project is due for it while still recoverable for the
        // other process: both are entitled to act, and the locks decide.
        'echo "purged".app(App\Domain\Trash\PurgeTrash::class)->handle(now()->addDays(2))["projects"];',
    ]);

    expect([['purged0', 'restored'], ['ModelNotFoundException', 'purged1']])->toContain($outcomes);

    $project = Project::withTrashed()->find($this->project->id);
    $outcomes === ['purged0', 'restored']
        ? expect($project)->not->toBeNull()->and($project?->trashed())->toBeFalse()
        : expect($project)->toBeNull();
});
