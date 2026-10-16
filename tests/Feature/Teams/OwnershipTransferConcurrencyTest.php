<?php

use App\Domain\Teams\TeamRole;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

// DATABASE.md §16 DB-T06: concurrent ownership transfers leave exactly one Owner with a matching
// owner membership. Two separate PHP processes race on committed data, so this test does not use
// RefreshDatabase (its transaction would hide the data from them); it cleans up after itself.
// Booting a PHP process takes far longer than a transfer, so without a barrier the two transfers
// would usually run one after the other. Each child waits on a shared advisory lock that this
// test holds exclusively, and the test releases it only once both children are queued on it.

const TRANSFER_BARRIER_KEY = 640_001;

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);

    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    $this->candidates = collect(range(1, 2))
        ->map(fn () => TeamMembership::factory()->for($this->team)->role(TeamRole::Admin)->create()->user);
});

afterEach(function () {
    $userIds = [$this->owner->id, ...$this->candidates->pluck('id')->all()];

    Team::withTrashed()->whereKey($this->team->id)->forceDelete();
    User::query()->whereKey($userIds)->delete();
});

/**
 * Runs TransferOwnership in a fresh `php artisan tinker` process against the same database.
 */
function transferInProcess(Team $team, User $owner, User $to): string
{
    $code = sprintf(
        'DB::select("select pg_advisory_lock_shared(%d)"); try { app(App\Domain\Teams\Actions\TransferOwnership::class)->handle(App\Models\Team::findOrFail("%s"), App\Models\User::findOrFail("%s"), "%s"); echo "transferred"; } catch (Throwable $e) { echo class_basename($e); }',
        TRANSFER_BARRIER_KEY,
        $team->id,
        $owner->id,
        $to->id,
    );

    return 'php artisan tinker --execute='.escapeshellarg($code).' --no-interaction';
}

it('serialises concurrent transfers: one wins, the other is refused, one Owner remains', function () {
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

    [$first, $second] = $this->candidates->all();

    DB::select('select pg_advisory_lock(?)', [TRANSFER_BARRIER_KEY]);

    try {
        $pool = Process::pool(fn ($pool) => [
            $pool->path(base_path())->env($env)->timeout(60)->command(transferInProcess($this->team, $this->owner, $first)),
            $pool->path(base_path())->env($env)->timeout(60)->command(transferInProcess($this->team, $this->owner, $second)),
        ])->start();

        $deadline = microtime(true) + 30;
        while (DB::table('pg_locks')->where('locktype', 'advisory')->where('objid', TRANSFER_BARRIER_KEY)->where('objsubid', 1)->whereRaw('database = (select oid from pg_database where datname = current_database())')->where('granted', false)->count() < 2) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Both transfer processes did not reach the barrier within 30 seconds.');
            }
            usleep(20_000);
        }
    } finally {
        DB::select('select pg_advisory_unlock(?)', [TRANSFER_BARRIER_KEY]);
    }

    $results = $pool->wait();

    $outcomes = $results->collect()->map(fn ($result) => trim($result->output()))->sort()->values()->all();

    expect($outcomes)->toBe(['DomainConflictException', 'transferred']);

    $team = $this->team->refresh();
    $owners = TeamMembership::query()->where('team_id', $team->id)->where('role', 'owner')->pluck('user_id')->all();

    expect($owners)->toHaveCount(1)
        ->and($owners[0])->toBe($team->owner_user_id)
        ->and([$first->id, $second->id])->toContain($team->owner_user_id)
        ->and($team->roleOf($this->owner))->toBe(TeamRole::Admin);

    expect(DB::table('team_memberships')->where('team_id', $team->id)->count())->toBe(3);
});
