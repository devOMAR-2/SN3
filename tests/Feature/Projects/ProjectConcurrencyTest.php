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

// CORE-003 under real concurrency (ADR-020 §5). Two PHP processes race on committed data, released
// together from an advisory-lock barrier, like OwnershipTransferConcurrencyTest (DB-T06). So this
// file does not use RefreshDatabase; it cleans up after itself.
//
// - Removing someone from a team while a manager adds them to a project: both writers lock the team
//   row first, so either the add commits first and the removal revokes it, or the add finds no team
//   membership (404). Never a project membership without a team membership, and never a 500.
// - Two creates with the same project key: one project, and a clean 409 for the loser.

const PROJECT_RACE_BARRIER_KEY = 640_003;

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);

    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->project = Project::factory()->for($this->team)->private()->create();
    $this->target = TeamMembership::factory()->for($this->team)->role(TeamRole::Member)->create();
});

afterEach(function () {
    $userIds = TeamMembership::query()->where('team_id', $this->team->id)->pluck('user_id')->push($this->target->user_id)->unique()->all();

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
function raceInProcesses(array $snippets): array
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
        PROJECT_RACE_BARRIER_KEY,
        $snippet,
    )), $snippets);

    DB::select('select pg_advisory_lock(?)', [PROJECT_RACE_BARRIER_KEY]);

    try {
        $pool = Process::pool(function ($pool) use ($commands, $env): void {
            foreach ($commands as $command) {
                $pool->path(base_path())->env($env)->timeout(60)->command($command);
            }
        })->start();

        $deadline = microtime(true) + 30;
        while (DB::table('pg_locks')->where('locktype', 'advisory')->where('objid', PROJECT_RACE_BARRIER_KEY)->where('objsubid', 1)->whereRaw('database = (select oid from pg_database where datname = current_database())')->where('granted', false)->count() < count($commands)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The racing processes did not reach the barrier within 30 seconds.');
            }
            usleep(20_000);
        }
    } finally {
        DB::select('select pg_advisory_unlock(?)', [PROJECT_RACE_BARRIER_KEY]);
    }

    return $pool->wait()->collect()->map(fn ($result) => trim($result->output()))->sort()->values()->all();
}

it('never leaves a project membership behind when team removal races a project add', function () {
    $outcomes = raceInProcesses([
        sprintf(
            'app(App\Domain\Teams\Actions\RemoveMember::class)->handle(App\Models\Team::findOrFail("%s"), App\Models\User::findOrFail("%s"), App\Models\TeamMembership::findOrFail("%s")); echo "removed";',
            $this->team->id,
            $this->owner->id,
            $this->target->id,
        ),
        sprintf(
            'app(App\Domain\Projects\Actions\SetProjectMember::class)->handle(App\Models\Project::findOrFail("%s"), App\Models\User::findOrFail("%s"), "%s", App\Domain\Projects\ProjectRole::Manager); echo "added";',
            $this->project->id,
            $this->owner->id,
            $this->target->user_id,
        ),
    ]);

    // Add first (then revoked by the removal), or removal first (then the add finds no member).
    expect([['added', 'removed'], ['ModelNotFoundException', 'removed']])->toContain($outcomes);

    expect(TeamMembership::query()->whereKey($this->target->id)->exists())->toBeFalse()
        ->and(ProjectMembership::query()->where('user_id', $this->target->user_id)->exists())->toBeFalse();
});

it('creates one project when two requests race for the same key', function () {
    $create = sprintf(
        'app(App\Domain\Projects\Actions\CreateProject::class)->handle(App\Models\Team::findOrFail("%s"), App\Models\User::findOrFail("%s"), "Race", "RACE"); echo "created";',
        $this->team->id,
        $this->owner->id,
    );

    expect(raceInProcesses([$create, $create]))->toBe(['DomainConflictException', 'created'])
        ->and(Project::query()->where('team_id', $this->team->id)->where('project_key', 'RACE')->count())->toBe(1)
        ->and(ProjectMembership::query()->where('team_id', $this->team->id)->where('user_id', $this->owner->id)->count())->toBe(1);
});
